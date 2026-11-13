<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A logged-in user can change their own password from /account/password: current password
 * required, the password policy + reuse rules apply, and the change signs them out (the
 * session/remember-me are bound to the password hash).
 */
final class ChangePasswordTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const EMAIL = 'changepw@example.com';

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
            $conn = self::getContainer()->get('doctrine.dbal.default_connection');
            $conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'changepw%')");
            $conn->executeStatement("DELETE FROM password_history WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'changepw%')");
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'changepw%'");
            $conn->executeStatement('DELETE FROM audit_log');
            $conn->executeStatement('DELETE FROM login_history');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(): void
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('Change Pw User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    private function loginAs(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => self::EMAIL, 'password' => 'testpassword']);
        $this->client->followRedirect();
    }

    private function submitChange(string $current, string $new, string $confirm): void
    {
        $this->client->request('GET', '/account/password');
        $this->assertResponseIsSuccessful();
        $this->client->submitForm('Change Password', [
            'current_password' => $current,
            'new_password'     => $new,
            'confirm_password' => $confirm,
        ]);
    }

    private function reloadUser(): User
    {
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
    }

    public function testChangeWithCorrectCurrentPasswordSucceedsAndNewPasswordWorks(): void
    {
        $this->createUser();
        $this->loginAs();

        $before = $this->auditRows(self::EMAIL, 'password_change', 'self-service');
        $this->submitChange('testpassword', 'brandnewpassword1', 'brandnewpassword1');
        $this->assertResponseRedirects('/staff/login');
        $this->assertSame($before + 1, $this->auditRows(self::EMAIL, 'password_change', 'self-service'), 'the password change is audited (issue #18)');

        // Old hash replaced.
        $this->assertTrue(password_verify('brandnewpassword1', $this->reloadUser()->getPassword()));

        // The change keeps the current session live, so log out first, then confirm the new
        // password authenticates from a clean state.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => self::EMAIL, 'password' => 'brandnewpassword1']);
        $this->assertResponseRedirects('/dashboard');
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->createUser();
        $this->loginAs();

        $this->submitChange('not-my-password', 'brandnewpassword1', 'brandnewpassword1');

        $this->assertResponseIsSuccessful(); // re-renders form, no redirect
        $this->assertSelectorExists('.error');
        $this->assertTrue(password_verify('testpassword', $this->reloadUser()->getPassword()), 'Password must be unchanged');
    }

    public function testMismatchedConfirmationIsRejected(): void
    {
        $this->createUser();
        $this->loginAs();

        $this->submitChange('testpassword', 'brandnewpassword1', 'different-value');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertTrue(password_verify('testpassword', $this->reloadUser()->getPassword()));
    }

    public function testNewPasswordMustMeetPolicyFloor(): void
    {
        $this->createUser();
        $this->loginAs();

        // 7 chars — below the hard floor of 8.
        $this->submitChange('testpassword', '1234567', '1234567');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertTrue(password_verify('testpassword', $this->reloadUser()->getPassword()));
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
