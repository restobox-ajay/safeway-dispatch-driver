<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-133 / review C23 — admin-impersonation authorization:
 *  (1) impersonation must run the same AdminChecker a real admin login runs, so an inactive
 *      admin cannot be impersonated into a live admin session;
 *  (2) demoting a superadmin must drop ROLE_SUPER_ADMIN from their live session on the next
 *      request (Admin::isEqualTo now compares roles).
 */
final class AdminImpersonationAuthzTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
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
            $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'imp_authz_test_%'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email, array $roles = [], string $status = 'active'): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Test Admin ' . $email);
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $admin->setStatus($status);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    private function login(string $email): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'adminpass',
        ]);
        $this->client->followRedirect();
    }

    // AC1/AC2/AC4: impersonating an inactive admin is rejected (AdminChecker runs), no live
    // impersonation session is created.
    public function testImpersonatingInactiveAdminIsRejected(): void
    {
        $superadmin = $this->createAdmin('imp_authz_test_super@example.com', ['ROLE_SUPER_ADMIN']);
        $inactive   = $this->createAdmin('imp_authz_test_inactive@example.com', [], 'inactive');

        $this->login($superadmin->getEmail());

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form    = $crawler
            ->filter('form[action="/admin/superadmin/admins/' . $inactive->getId() . '/impersonate"]')
            ->form();
        $this->client->submit($form);

        // Rejected fail-closed: bounced back to the admin list, NOT to the impersonated
        // /admin/dashboard (the success target).
        $this->assertResponseRedirects('/admin/superadmin/admins');

        $this->client->followRedirect();
        // Still the superadmin: the ROLE_SUPER_ADMIN-only management page is reachable, so no
        // switch to the inactive target happened.
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('admin-impersonation-banner', $content);
        $this->assertStringContainsString('cannot be impersonated', $content);
    }

    // AC3/AC5: demoting a superadmin with a live session removes ROLE_SUPER_ADMIN on the next
    // request (Admin::isEqualTo compares roles -> ContextListener deauthenticates the stale token).
    public function testDemotingSuperadminDropsRoleInLiveSession(): void
    {
        // Two active superadmins so the anti-lockout guard does not block the demotion, and so
        // the demoted one still has a live session to test.
        $this->createAdmin('imp_authz_test_superA@example.com', ['ROLE_SUPER_ADMIN']);
        $victim = $this->createAdmin('imp_authz_test_superB@example.com', ['ROLE_SUPER_ADMIN']);
        $victimId = $victim->getId();

        // The victim has a live superadmin session.
        $this->login('imp_authz_test_superB@example.com');
        $this->client->request('GET', '/admin/superadmin/admins');
        $this->assertResponseIsSuccessful();

        // Superadmin A demotes the victim to ROLE_ADMIN (persisted). Simulated via the entity
        // manager to keep a single live session under test; the isEqualTo mechanism is identical
        // regardless of which superadmin performed the edit.
        $fresh = $this->em->getRepository(Admin::class)->find($victimId);
        $fresh->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->em->clear();

        // Next request on the victim's live session: the stale ROLE_SUPER_ADMIN token no longer
        // matches the refreshed (now ROLE_ADMIN) user, so the token is deauthenticated and the
        // ROLE_SUPER_ADMIN-only page is no longer authorized.
        $this->client->request('GET', '/admin/superadmin/admins');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
