<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Superadmin management of Admin accounts: create, edit (name/email/role/status), delete,
 * enforced deactivation, and the anti-lockout guards (never strip the last active
 * superadmin; never delete/deactivate yourself into a lockout).
 */
final class AdminManagementTest extends WebTestCase
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
            // Drop admin_sessions for the test admins BEFORE the admin rows so nothing lingers
            // (FEATURE-148).
            $this->em->getConnection()->executeStatement(
                "DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE 'admgmt-%@example.com')"
            );
            $this->em->getConnection()->executeStatement("DELETE FROM admin WHERE email LIKE 'admgmt-%@example.com'");
            $this->em->getConnection()->executeStatement("DELETE FROM audit_log");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function adminSessionCount(int $adminId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ?',
            [$adminId]
        );
    }

    private function makeAdmin(string $email, array $roles, string $status = 'active'): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Mgmt ' . $email);
        $admin->setPassword(password_hash('superpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $admin->setStatus($status);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email, string $password = 'superpass'): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => $password]);
    }

    private function reload(string $email): ?Admin
    {
        $this->em->clear();
        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    public function testSuperadminCreatesAnAdmin(): void
    {
        $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/new');
        $this->client->submitForm('Create Admin', [
            'email'    => 'admgmt-created@example.com',
            'name'     => 'Created Admin',
            'password' => 'password123',
            'role'     => 'ROLE_ADMIN',
        ]);
        $this->assertResponseRedirects('/admin/superadmin/admins');

        $created = $this->reload('admgmt-created@example.com');
        $this->assertNotNull($created);
        $this->assertContains('ROLE_ADMIN', $created->getRoles());
        $this->assertSame('active', $created->getStatus());
    }

    public function testEditPromotesAndDemotesRoleWhenAnotherSuperExists(): void
    {
        $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('admgmt-target@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        // Promote to superadmin.
        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'admgmt-target@example.com',
            'name'   => 'Target',
            'role'   => 'ROLE_SUPER_ADMIN',
            'status' => 'active',
        ]);
        $this->assertResponseRedirects('/admin/superadmin/admins');
        $this->assertContains('ROLE_SUPER_ADMIN', $this->reload('admgmt-target@example.com')->getRoles());

        // Demote again (now there are two supers, so the guard allows it).
        $id = $this->reload('admgmt-target@example.com')->getId();
        $this->client->request('GET', '/admin/superadmin/admins/' . $id . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'admgmt-target@example.com',
            'name'   => 'Target',
            'role'   => 'ROLE_ADMIN',
            'status' => 'active',
        ]);
        $this->assertResponseRedirects('/admin/superadmin/admins');
        $this->assertNotContains('ROLE_SUPER_ADMIN', $this->reload('admgmt-target@example.com')->getRoles());
    }

    public function testDeactivatedAdminCannotLogIn(): void
    {
        $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('admgmt-victim@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'admgmt-victim@example.com',
            'name'   => 'Victim',
            'role'   => 'ROLE_ADMIN',
            'status' => 'inactive',
        ]);
        $this->assertResponseRedirects('/admin/superadmin/admins');
        $this->assertSame('inactive', $this->reload('admgmt-victim@example.com')->getStatus());

        // The deactivated admin can no longer log in (AdminChecker → DisabledException).
        $this->client->request('GET', '/admin/logout');
        $this->loginAs('admgmt-victim@example.com');
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ADR-020 / FEATURE-110: deleting an admin is a SOFT delete — the row stays, status→inactive.
    public function testDeleteSoftDeletesAdmin(): void
    {
        $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('admgmt-del@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $target->getId() . '/delete"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/superadmin/admins');
        $reloaded = $this->reload('admgmt-del@example.com');
        $this->assertNotNull($reloaded, 'Soft delete must keep the admin row');
        $this->assertSame('inactive', $reloaded->getStatus());
    }

    // FEATURE-148 / ADR-049: soft-deleting an admin tears down its admin_sessions bookkeeping rows in
    // the same flow, so they stop lingering as ghost "active sessions" and the admin is logged out on
    // its next request. Asserts the ROW DELETION explicitly — Admin::isEqualTo status-deauth must not
    // be the only thing making this pass.
    public function testSoftDeleteTearsDownAdminSessionsAndBouncesToLogin(): void
    {
        $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('admgmt-ghost@example.com', ['ROLE_ADMIN']);

        // The target admin logs in from "their browser" — this creates an admin_sessions row.
        $this->loginAs('admgmt-ghost@example.com');
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $this->adminSessionCount((int) $target->getId()), 'login created an admin_sessions row');

        // Preserve the target admin's live-session cookies, then start a fresh "browser" (clear the
        // jar) for the superadmin — we must NOT log the target out (logout would delete the row and
        // defeat the test).
        $targetCookies = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();

        // Superadmin soft-deletes the target from the management list.
        $this->loginAs('admgmt-super@example.com');
        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $target->getId() . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/superadmin/admins');

        // Explicit row-deletion assertion (AC1/AC2): the soft-delete removed every admin_sessions row
        // for the target.
        $this->assertSame(0, $this->adminSessionCount((int) $target->getId()), 'soft-delete tore down all admin_sessions rows for the target');

        // Restore the target admin's live-session cookies and hit a protected page: with the row gone,
        // AdminSessionRequestListener invalidates the session and bounces to /admin/login.
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($targetCookies as $cookie) {
            $jar->set($cookie);
        }

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCannotDeleteSelf(): void
    {
        $super = $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $super->getId() . '/delete"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/superadmin/admins');
        $this->assertNotNull($this->reload('admgmt-super@example.com'), 'A superadmin must not be able to delete themselves');
    }

    public function testCannotDemoteLastSuperadmin(): void
    {
        $super = $this->makeAdmin('admgmt-super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admgmt-super@example.com');

        // Only one superadmin exists — editing self down to ROLE_ADMIN must be refused.
        $this->client->request('GET', '/admin/superadmin/admins/' . $super->getId() . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'admgmt-super@example.com',
            'name'   => 'Super',
            'role'   => 'ROLE_ADMIN',
            'status' => 'active',
        ]);
        // Re-renders with an error, no redirect; role unchanged.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertContains('ROLE_SUPER_ADMIN', $this->reload('admgmt-super@example.com')->getRoles());
    }

    public function testRegularAdminCannotAccessManagement(): void
    {
        $this->makeAdmin('admgmt-regular@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admgmt-regular@example.com');

        $this->client->request('GET', '/admin/superadmin/admins');
        $this->assertResponseStatusCodeSame(403);
    }
}
