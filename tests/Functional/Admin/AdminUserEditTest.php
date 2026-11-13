<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Security\IpWhitelistManagerInterface;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserEditTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $admin = new Admin();
        $admin->setEmail('useredit-admin@example.com');
        $admin->setName('Edit Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
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
            $conn->executeStatement("DELETE FROM personal_access_tokens WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'useredit-%@example.com')");
            $conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'useredit-%@example.com')");
            $conn->executeStatement("DELETE FROM admin_access_tokens WHERE admin_id IN (SELECT id FROM admin WHERE email = 'useredit-admin@example.com')");
            $conn->executeStatement("DELETE FROM audit_log WHERE actor = 'useredit-admin@example.com'");
            $this->em->getConnection()->executeStatement(
                "DELETE FROM \"user\" WHERE email LIKE 'useredit-%@example.com'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'useredit-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $status = 'active', array $roles = []): User
    {
        return $this->createTestUser($email, 'Test User', 'userpass', $status, $roles);
    }

    private function loginAsUser(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'userpass',
        ]);
        $this->client->followRedirect();
    }

    // AC1: GET /admin/users/{id}/edit renders a form pre-filled with the user's current values
    public function testEditFormRendersPrefilledWithCurrentValues(): void
    {
        $user = $this->createUser('useredit-prefill@example.com', 'active', []);
        $this->loginAsAdmin('useredit-admin@example.com');

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('useredit-prefill@example.com', $content);
        $this->assertStringContainsString('Test User', $content);
        $this->assertStringContainsString('name="email"', $content);
        $this->assertStringContainsString('name="name"', $content);
        $this->assertStringContainsString('name="role"', $content);
        $this->assertStringContainsString('name="status"', $content);
    }

    // AC2: POST with valid changes updates the user in the database
    public function testValidChangesUpdateUser(): void
    {
        $user = $this->createUser('useredit-update@example.com', 'active', []);
        $this->loginAsAdmin('useredit-admin@example.com');

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Save Changes', [
            'email'  => 'useredit-updated@example.com',
            'name'   => 'Updated Name',
            'role'   => 'ROLE_USER',
            'status' => 'inactive',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        $updated = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($updated);
        $this->assertSame('useredit-updated@example.com', $updated->getEmail());
        $this->assertSame('Updated Name', $updated->getName());
        $this->assertSame('inactive', $updated->getStatus());

        // Clean up the renamed email
        $this->em->getConnection()->executeStatement(
            "DELETE FROM \"user\" WHERE email = 'useredit-updated@example.com'"
        );
    }

    // AC3: Changing status to inactive terminates that user's active sessions
    public function testChangingStatusToInactiveTerminatesUserSession(): void
    {
        $this->createUser('useredit-session@example.com', 'active', []);

        // User logs in and accesses protected route
        $this->loginAsUser('useredit-session@example.com');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // Status flipped straight in the DB: this pins the refresh-time logout (User::isEqualTo compares status).
        // The real edit-form / API path, incl. session + PAT + remember-me teardown, is covered by
        // testDeactivatingThroughTheEditFormCutsLiveAccessAndReactivationRevivesNothing (issue #61).
        $this->em->getConnection()->executeStatement(
            "UPDATE \"user\" SET status = 'inactive' WHERE email = :email",
            ['email' => 'useredit-session@example.com']
        );
        $this->em->clear();

        // User's next request: ContextListener refreshes the user (now inactive); User::isEqualTo sees the
        // status change and the session is deauthenticated
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // The admin user-edit form can set the per-user allowed-IPs whitelist
    // (auth-ip-whitelist-bundle), which previously had no admin UI.
    public function testAdminCanSetPerUserAllowedIps(): void
    {
        $user = $this->createUser('useredit-ips@example.com', 'active', []);
        $this->loginAsAdmin('useredit-admin@example.com');

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'       => 'useredit-ips@example.com',
            'name'        => 'Test User',
            'status'      => 'active',
            'allowed_ips' => '203.0.113.4, 198.51.100.0/24',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        $updated = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($updated);
        // The per-user override now lives in the auth-ip-whitelist-bundle satellite, read through the core
        // port (FEATURE-146), not on the User entity.
        $ipWhitelist = self::getContainer()->get(IpWhitelistManagerInterface::class);
        $this->assertSame('203.0.113.4, 198.51.100.0/24', $ipWhitelist->getAllowedIps($updated));
    }

    /**
     * Issue #61: give the user live credentials of every kind — a session row and a personal access token — and
     * return the PAT's plaintext.
     */
    private function giveLiveCredentials(User $user): string
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement('INSERT INTO user_sessions (session_id, user_id, ip, user_agent, created_at, last_active_at) VALUES (?, ?, ?, ?, ?, ?)', ['useredit-sess-' . $user->getId(), $user->getId(), '127.0.0.1', 'phpunit', $now, $now]);
        $pat = bin2hex(random_bytes(32));
        $conn->insert('personal_access_tokens', ['user_id' => $user->getId(), 'name' => 'stolen', 'token_hash' => hash('sha256', $pat), 'created_at' => $now]);

        return $pat;
    }

    private function assertLiveAccessWasCut(User $user, string $pat, string $how): void
    {
        $conn = $this->em->getConnection();
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user->getId()]), "$how: session rows are dropped");
        $this->assertNotNull($conn->fetchOne('SELECT revoked_at FROM personal_access_tokens WHERE token_hash = ?', [hash('sha256', $pat)]), "$how: PATs are revoked");
        $this->assertNotNull($conn->fetchOne('SELECT sessions_invalidated_at FROM "user" WHERE id = ?', [$user->getId()]), "$how: remember-me cookies are invalidated");
        $this->assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.user_deactivate' AND actor = 'useredit-admin@example.com'"), "$how: audited as a deactivation");
    }

    private function patStatus(string $pat): int
    {
        $browser = clone $this->client;
        $browser->getCookieJar()->clear();
        $browser->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $pat]);

        return $browser->getResponse()->getStatusCode();
    }

    public function testDeactivatingThroughTheEditFormCutsLiveAccessAndReactivationRevivesNothing(): void
    {
        $user = $this->createUser('useredit-cut@example.com', 'active', []);
        $pat = $this->giveLiveCredentials($user);
        $this->assertSame(200, $this->patStatus($pat));
        $this->loginAsAdmin('useredit-admin@example.com');

        $edit = function (string $status) use ($user): void {
            $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
            $this->client->submitForm('Save Changes', ['email' => 'useredit-cut@example.com', 'name' => 'Test User', 'role' => 'ROLE_USER', 'status' => $status]);
            $this->assertResponseRedirects('/admin/users');
        };

        $edit('inactive');
        $this->assertLiveAccessWasCut($user, $pat, 'edit form');

        $edit('active');
        $this->assertSame(401, $this->patStatus($pat), 'a stolen PAT must not come back with the account');
    }

    public function testDeactivatingThroughTheApiPatchCutsLiveAccessAsDocumented(): void
    {
        $user = $this->createUser('useredit-api@example.com', 'active', []);
        $pat = $this->giveLiveCredentials($user);
        $adminToken = bin2hex(random_bytes(32));
        $this->em->getConnection()->executeStatement(
            "INSERT INTO admin_access_tokens (admin_id, name, token_hash, created_at) SELECT id, 'edit test', ?, ? FROM admin WHERE email = 'useredit-admin@example.com'",
            [hash('sha256', $adminToken), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $patch = function (string $status) use ($user, $adminToken): void {
            $this->client->request('PATCH', '/admin-api/users/' . $user->getId(), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken, 'CONTENT_TYPE' => 'application/json'], json_encode(['status' => $status]));
            $this->assertResponseIsSuccessful();
        };

        $patch('inactive');
        $this->assertLiveAccessWasCut($user, $pat, 'PATCH');

        $patch('active');
        $this->assertSame(401, $this->patStatus($pat));
    }
}

