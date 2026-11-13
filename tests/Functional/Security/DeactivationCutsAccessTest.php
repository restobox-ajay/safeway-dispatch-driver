<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-098 (review C3): deactivation and "logout everywhere" must actually cut access —
 * the remember-me cookie must stop re-authenticating, and a deactivated user's/admin's tokens
 * must be rejected on the stateless API firewalls.
 */
final class DeactivationCutsAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private const PASSWORD = 'testpassword';

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
            $this->conn->executeStatement('DELETE FROM personal_access_tokens');
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'deactadmin%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'deact%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement('DELETE FROM user_sessions');
            // Clear throttle state so an accumulated per-IP count from earlier suites in the
            // full run cannot rate-limit the form logins these tests perform.
            $this->conn->executeStatement('DELETE FROM login_attempts');
            // A leftover empty remember_me.lifetime_days config row (from a config-save test in
            // the full suite) makes getInt() return 0 -> the cookie expires immediately and the
            // jar drops it. Remove it so the default 30-day lifetime applies (matches RememberMeTest).
            $this->conn->executeStatement("DELETE FROM config WHERE config_key = 'remember_me.lifetime_days'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $status = 'active'): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Deact Test User');
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne("SELECT id FROM \"user\" WHERE email = ?", [$email]);
    }

    private function seedUserPat(int $userId): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => 'Deact PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createAdmin(string $email, string $status = 'active'): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Deact Admin');
        $admin->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setStatus($status);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne('SELECT id FROM admin WHERE email = ?', [$email]);
    }

    private function seedAdminToken(int $adminId): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Deact Admin Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    // AC2: a deactivated user's existing PAT is rejected on /api (401)
    public function testDeactivatedUserPatIsRejectedOnApi(): void
    {
        $userId = $this->createUser('deactpat@example.com', 'active');
        $token  = $this->seedUserPat($userId);

        // Sanity: while active, the PAT authenticates.
        $this->client->request('GET', '/api/ping', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(200);

        // Deactivate the user directly (mirrors any deactivation path).
        $this->conn->executeStatement('UPDATE "user" SET status = ? WHERE id = ?', ['inactive', $userId]);

        $this->client->request('GET', '/api/ping', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(401, 'A deactivated user PAT must be rejected on /api');
        $this->assertJson($this->client->getResponse()->getContent());
    }

    // AC3: a deactivated admin's existing admin token is rejected on /admin-api (401)
    public function testDeactivatedAdminTokenIsRejectedOnAdminApi(): void
    {
        $adminId = $this->createAdmin('deactadmin@example.com', 'active');
        $token   = $this->seedAdminToken($adminId);

        // Sanity: while active, the admin token authenticates.
        $this->client->request('GET', '/admin-api/users', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(200);

        $this->conn->executeStatement('UPDATE admin SET status = ? WHERE id = ?', ['inactive', $adminId]);

        $this->client->request('GET', '/admin-api/users', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(401, 'A deactivated admin token must be rejected on /admin-api');
        $this->assertJson($this->client->getResponse()->getContent());
    }

    // AC4: deactivating a user (via the admin API) drops their sessions and revokes their PATs
    public function testDeactivateDropsSessionsAndRevokesPats(): void
    {
        $userId = $this->createUser('deactdrop@example.com', 'active');
        $pat    = $this->seedUserPat($userId);

        $this->conn->insert('user_sessions', [
            'session_id'     => 'deact-sess-1',
            'user_id'        => $userId,
            'ip'             => '127.0.0.1',
            'user_agent'     => 'TestAgent/1.0',
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_active_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $adminId    = $this->createAdmin('deactadmin2@example.com', 'active');
        $adminToken = $this->seedAdminToken($adminId);

        $this->client->request('POST', '/admin-api/users/' . $userId . '/deactivate', [], [], $this->bearer($adminToken));
        $this->assertResponseStatusCodeSame(200);

        $sessionCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);
        $this->assertSame(0, $sessionCount, 'Deactivation must drop the user\'s sessions');

        $revokedAt = $this->conn->fetchOne('SELECT revoked_at FROM personal_access_tokens WHERE user_id = ?', [$userId]);
        $this->assertNotNull($revokedAt, 'Deactivation must revoke the user\'s PATs');

        // And the PAT no longer authenticates.
        $this->client->request('GET', '/api/ping', [], [], $this->bearer($pat));
        $this->assertResponseStatusCodeSame(401);
    }

    // AC1 (web): after "logout everywhere" a previously issued remember-me cookie no longer re-authenticates
    public function testRememberMeCookieDeadAfterWebLogoutEverywhere(): void
    {
        $this->createUser('deactweb@example.com', 'active');

        $this->client->request('POST', '/staff/login', [
            'email'        => 'deactweb@example.com',
            'password'     => self::PASSWORD,
            '_remember_me' => '1',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $rememberMe = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($rememberMe, 'REMEMBERME cookie must be issued at login');

        // Trigger "logout everywhere" via the sessions page form (carries the CSRF token).
        $crawler = $this->client->request('GET', '/account/sessions');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Logout Everywhere')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/staff/login');

        // Simulate a fresh browser session: keep only the (old) remember-me cookie.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($rememberMe);

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects(
            '/staff/login',
            null,
            'Remember-me must NOT re-authenticate after logout-everywhere'
        );
    }

    // AC1 (API): after admin force-logout the user's remember-me cookie no longer re-authenticates
    public function testRememberMeCookieDeadAfterApiForceLogout(): void
    {
        $userId = $this->createUser('deactforce@example.com', 'active');

        $this->client->request('POST', '/staff/login', [
            'email'        => 'deactforce@example.com',
            'password'     => self::PASSWORD,
            '_remember_me' => '1',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $rememberMe = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($rememberMe, 'REMEMBERME cookie must be issued at login');

        $adminId    = $this->createAdmin('deactadmin3@example.com', 'active');
        $adminToken = $this->seedAdminToken($adminId);

        $this->client->request('POST', '/admin-api/users/' . $userId . '/force-logout', [], [], $this->bearer($adminToken));
        $this->assertResponseStatusCodeSame(200);

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($rememberMe);

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects(
            '/staff/login',
            null,
            'Remember-me must NOT re-authenticate after admin force-logout'
        );
    }
}
