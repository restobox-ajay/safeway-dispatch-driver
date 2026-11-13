<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-118 / review C26: the impersonation-exit endpoints must be a no-op when no
 * impersonation is in progress — no garbage 'unknown -> unknown' audit row, and no session
 * teardown that would log the caller out.
 */
final class ImpersonationExitGuardTest extends WebTestCase
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
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'impguard_%'");
            $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'impguard_%'");
            $conn->executeStatement("DELETE FROM audit_log WHERE action IN ('admin.impersonate_exit', 'admin.impersonate_admin_exit')");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function auditCount(string $action): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = ?',
            [$action]
        );
    }

    // AC1/AC3: a plain user POSTing exit with no impersonation active is a no-op — no audit row,
    // and the user stays logged in.
    public function testUserExitWithNoImpersonationIsNoOp(): void
    {
        $user = new User();
        $user->setEmail('impguard_user@example.com');
        $user->setName('Guard User');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $user->setRoles([]);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'impguard_user@example.com',
            'password' => 'userpass',
        ]);
        $this->client->followRedirect(); // land on /dashboard, authenticated

        $before = $this->auditCount('admin.impersonate_exit');

        $this->client->request('POST', '/impersonate/exit');

        // No-op: redirect to the dashboard, no audit row written.
        $this->assertResponseStatusCodeSame(302);
        $this->assertSame($before, $this->auditCount('admin.impersonate_exit'), 'No audit row should be written when no impersonation is active');

        // Caller was NOT logged out: the protected dashboard is still reachable.
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
    }

    // AC1/AC3: a plain admin POSTing the admin-exit endpoint with no impersonation active is a
    // no-op — no audit row, and the admin stays logged in.
    public function testAdminExitWithNoImpersonationIsNoOp(): void
    {
        $admin = new Admin();
        $admin->setEmail('impguard_admin@example.com');
        $admin->setName('Guard Admin');
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles([]);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'impguard_admin@example.com',
            'password' => 'adminpass',
        ]);
        $this->client->followRedirect(); // land on /admin/dashboard, authenticated

        $before = $this->auditCount('admin.impersonate_admin_exit');

        $this->client->request('POST', '/admin/impersonate-admin-exit');

        $this->assertResponseStatusCodeSame(302);
        $this->assertSame($before, $this->auditCount('admin.impersonate_admin_exit'), 'No audit row should be written when no admin impersonation is active');

        // Caller was NOT logged out: the protected admin dashboard is still reachable.
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
    }
}
