<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FEATURE-092 — audit of the remaining ROLE_ADMIN / ROLE_SUPER_ADMIN usages.
 *
 * After FEATURE-081 admin authorization is entity-based: the `admin` (^/admin)
 * and `admin_api` (^/admin-api) firewalls authenticate Admin entities; the
 * `user` firewall authenticates User entities. These tests lock in the
 * invariants the audit (.agent/ROLE_AUDIT_092.md) confirmed, complementing
 * AdminApiBoundaryTest (which already covers the /admin-api boundary):
 *
 *   - The web ^/admin boundary does NOT grant access off a *User's* role string,
 *     even when the user's roles column is force-injected with admin roles.
 *   - The sidebar nav is keyed off the authenticated entity (instanceof), not a
 *     role string, so a user never sees the admin nav.
 *   - is_granted('ROLE_SUPER_ADMIN') in _admin_nav reflects the authenticated
 *     Admin's actual identity (regular admin vs superadmin).
 */
final class RoleBoundaryAuditTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const USER_EMAIL        = 'audit092_user@example.com';
    private const ADMIN_EMAIL       = 'audit092_admin@example.com';
    private const SUPERADMIN_EMAIL  = 'audit092_superadmin@example.com';
    private const PASSWORD          = 'testpassword';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement(
                'DELETE FROM "user" WHERE email = ?',
                [self::USER_EMAIL]
            );
            $this->conn->executeStatement(
                'DELETE FROM admin WHERE email IN (?, ?)',
                [self::ADMIN_EMAIL, self::SUPERADMIN_EMAIL]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Create a User, then force-inject admin roles directly at the DB level,
     * bypassing User::setRoles()'s allowlist (worst case / simulated injection).
     */
    private function createUserWithInjectedAdminRoles(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(self::USER_EMAIL);
        $user->setName('Audit User');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $id = (int) $user->getId();
        $this->em->clear();

        $this->conn->executeStatement(
            'UPDATE "user" SET roles = ? WHERE id = ?',
            ['["ROLE_ADMIN","ROLE_SUPER_ADMIN"]', $id]
        );
    }

    private function createAdmin(string $email, array $roles): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Audit Admin');
        $admin->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
    }

    private function loginAdmin(string $email): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * AC2/AC3: a User authenticated on the user firewall — even with DB-injected
     * admin roles — cannot reach the web admin area. The ^/admin access_control
     * is enforced inside the admin firewall, whose token is empty here; the
     * user's role string does not apply across the firewall boundary.
     */
    public function testUserWithInjectedAdminRoleCannotAccessAdminWeb(): void
    {
        $this->createUserWithInjectedAdminRoles();
        $this->loginUser(self::USER_EMAIL);

        // Sanity: the user IS authenticated on the user firewall.
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // ...but the admin firewall does not recognize that identity.
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/admin/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );

        // Same for an admin user-management route.
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/admin/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    /**
     * AC3: the sidebar follows the authenticated entity, not a role string. A
     * user with injected admin roles, on a user page, sees the USER nav and not
     * the admin nav.
     */
    public function testUserWithInjectedAdminRoleSeesUserNavNotAdminNav(): void
    {
        $this->createUserWithInjectedAdminRoles();
        $this->loginUser(self::USER_EMAIL);

        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent() ?: '';

        // Admin nav markers must be absent.
        $this->assertStringNotContainsString('Admin Panel', $html);
        $this->assertSelectorNotExists('a[href="/admin/config"]');
        $this->assertSelectorNotExists('a[href="/admin/audit-log"]');

        // User nav markers must be present (proves the user nav rendered).
        $this->assertSelectorExists('a[href="/account/tokens"]');
    }

    /**
     * A regular Admin (ROLE_ADMIN only) sees the admin nav but NOT the
     * superadmin-only section — proves is_granted('ROLE_SUPER_ADMIN') in
     * _admin_nav reflects the Admin's real identity (negative case).
     */
    public function testRegularAdminSeesNoSuperadminNavLink(): void
    {
        $this->createAdmin(self::ADMIN_EMAIL, []);
        $this->loginAdmin(self::ADMIN_EMAIL);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();

        // Admin nav rendered...
        $this->assertSelectorExists('a[href="/admin/config"]');
        // ...but the superadmin-only link is absent.
        $this->assertSelectorNotExists('a[href="/admin/superadmin/admins"]');
    }

    /**
     * A superadmin (ROLE_SUPER_ADMIN) sees the superadmin-only nav section —
     * proves is_granted('ROLE_SUPER_ADMIN') in _admin_nav reflects the Admin's
     * real identity (positive case).
     */
    public function testSuperadminSeesSuperadminNavLink(): void
    {
        $this->createAdmin(self::SUPERADMIN_EMAIL, ['ROLE_SUPER_ADMIN']);
        $this->loginAdmin(self::SUPERADMIN_EMAIL);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('a[href="/admin/superadmin/admins"]');
    }
}
