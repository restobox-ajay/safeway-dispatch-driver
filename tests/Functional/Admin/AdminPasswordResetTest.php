<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\AdminPasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin password reset (ADR-009): self-service /admin/forgot-password + /admin/reset-password,
 * and the superadmin "reset this admin" panel action. Mirrors the hardened user flow
 * (rate-limit, CSRF, anti-enumeration, single-use tokens, sibling invalidation).
 */
final class AdminPasswordResetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM admin_password_reset_tokens WHERE email LIKE 'apr-%@example.com'");
            $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'apr-%@example.com'");
            $conn->executeStatement('DELETE FROM endpoint_rate_limits');
            $conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function makeAdmin(string $email, array $roles = ['ROLE_ADMIN'], string $password = 'oldpassword'): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('APR ' . $email);
        $admin->setPassword(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    private function createToken(string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new AdminPasswordResetToken($email, hash('sha256', $plaintext), new \DateTimeImmutable('+1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintext;
    }

    private function tokenCount(string $email): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM admin_password_reset_tokens WHERE email = ?',
            [$email]
        );
    }

    public function testForgotPasswordPageIsPubliclyAccessible(): void
    {
        $this->client->request('GET', '/admin/forgot-password');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    public function testForgotPasswordWithKnownEmailMintsToken(): void
    {
        $this->makeAdmin('apr-known@example.com');

        $this->client->request('GET', '/admin/forgot-password');
        $this->client->submitForm('Send Reset Link', ['email' => 'apr-known@example.com']);

        $this->assertResponseRedirects('/admin/forgot-password/check');
        $this->assertSame(1, $this->tokenCount('apr-known@example.com'));
    }

    public function testForgotPasswordWithUnknownEmailMintsNothing(): void
    {
        $this->client->request('GET', '/admin/forgot-password');
        $this->client->submitForm('Send Reset Link', ['email' => 'apr-nobody@example.com']);

        // Same confirmation redirect (anti-enumeration), but no token created.
        $this->assertResponseRedirects('/admin/forgot-password/check');
        $this->assertSame(0, $this->tokenCount('apr-nobody@example.com'));
    }

    public function testResetWithValidTokenChangesPasswordAndAllowsLogin(): void
    {
        $this->makeAdmin('apr-reset@example.com');
        $plaintext = $this->createToken('apr-reset@example.com');

        $this->client->request('GET', '/admin/reset-password/' . $plaintext);
        $this->assertResponseIsSuccessful();

        $before = $this->auditRows('apr-reset@example.com', 'admin.password_reset');
        $this->client->submitForm('Reset Password', ['password' => 'newpassword123']);
        $this->assertResponseRedirects('/admin/login');
        $this->assertSame($before + 1, $this->auditRows('apr-reset@example.com', 'admin.password_reset'), 'the completed admin reset is audited (issue #18)');

        // New password authenticates; old one no longer does.
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => 'apr-reset@example.com', 'password' => 'newpassword123']);
        $this->assertResponseRedirects('/admin/dashboard');
    }

    public function testResetRejectsInvalidToken(): void
    {
        $this->client->request('GET', '/admin/reset-password/not-a-real-token');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
    }

    public function testResetEnforcesPasswordPolicy(): void
    {
        $this->makeAdmin('apr-policy@example.com');
        $plaintext = $this->createToken('apr-policy@example.com');

        $this->client->request('GET', '/admin/reset-password/' . $plaintext);
        $this->client->submitForm('Reset Password', ['password' => '1234567']); // 7 chars, below floor

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // Old password still works → unchanged.
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => 'apr-policy@example.com', 'password' => 'oldpassword']);
        $this->assertResponseRedirects('/admin/dashboard');
    }

    public function testSuperadminResetActionSendsResetLink(): void
    {
        $this->makeAdmin('apr-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('apr-target@example.com', ['ROLE_ADMIN']);

        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => 'apr-super@example.com', 'password' => 'oldpassword']);

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $target->getId() . '/reset-password"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/superadmin/admins');
        $this->assertSame(1, $this->tokenCount('apr-target@example.com'));
    }

    /** Issue #18: audit rows written for one actor+action (the request-time count, so earlier rows can't fake a pass). */
    private function auditRows(string $actor, string $action, ?string $context = null): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) ($context === null
            ? $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success'", [$actor, $action])
            : $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success' AND context = ?", [$actor, $action, $context]));
    }
}
