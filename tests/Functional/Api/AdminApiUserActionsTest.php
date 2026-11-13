<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiUserActionsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $adminToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
        $this->cleanup();
        $this->adminToken = $this->createAdminToken();
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
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'api%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apiaction%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement('DELETE FROM user_sessions');
            $this->conn->executeStatement('DELETE FROM password_reset_tokens');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('apiactionadmin@example.com');
        $admin->setName('API Action Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'apiactionadmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => $hash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createUserFixture(string $email, string $status = 'active'): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Action Test User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne("SELECT id FROM \"user\" WHERE email = ?", [$email]);
    }

    private function apiPost(string $url, ?string $token = null): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->adminToken)];
        $this->client->request('POST', $url, [], [], $headers);
    }

    public function testActivateSetsStatusActiveAndReturns200(): void
    {
        $userId = $this->createUserFixture('apiactionactivate@example.com', 'inactive');

        $this->apiPost('/admin-api/users/' . $userId . '/activate');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);

        $row = $this->conn->fetchAssociative('SELECT * FROM "user" WHERE id = ?', [$userId]);
        $this->assertEquals('active', $row['status']);
    }

    public function testDeactivateSetsStatusInactiveAndReturns200(): void
    {
        $userId = $this->createUserFixture('apiactiondeactivate@example.com', 'active');

        $this->apiPost('/admin-api/users/' . $userId . '/deactivate');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);

        $row = $this->conn->fetchAssociative('SELECT * FROM "user" WHERE id = ?', [$userId]);
        $this->assertEquals('inactive', $row['status']);
    }

    public function testForceLogoutRevokesAllUserSessionsAndReturns200(): void
    {
        $userId = $this->createUserFixture('apiactionforcelogout@example.com');

        // seed two user_sessions rows
        $this->conn->insert('user_sessions', [
            'session_id'     => 'sess-action-1',
            'user_id'        => $userId,
            'ip'             => '127.0.0.1',
            'user_agent'     => 'TestAgent/1.0',
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_active_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->conn->insert('user_sessions', [
            'session_id'     => 'sess-action-2',
            'user_id'        => $userId,
            'ip'             => '127.0.0.1',
            'user_agent'     => 'TestAgent/2.0',
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_active_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->apiPost('/admin-api/users/' . $userId . '/force-logout');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);
        $this->assertEquals(0, (int) $count);
    }

    public function testPasswordResetTriggersEmailAndReturns200(): void
    {
        $userId = $this->createUserFixture('apiactionpwreset@example.com');

        $this->apiPost('/admin-api/users/' . $userId . '/password-reset');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);

        // assert a PasswordResetToken was created for this user's email
        $row = $this->conn->fetchAssociative(
            "SELECT * FROM password_reset_tokens WHERE email = 'apiactionpwreset@example.com'"
        );
        $this->assertNotFalse($row, 'A password_reset_token row must exist after /password-reset action');
        $this->assertNull($row['used_at'], 'Token must not be already used');
        $this->assertGreaterThan(
            time(),
            strtotime($row['expires_at']),
            'Token must not be already expired'
        );
    }

    public function testAllActionEndpointsReturn401WithoutValidToken(): void
    {
        $userId = $this->createUserFixture('apiaction401@example.com');

        $routes = [
            '/admin-api/users/' . $userId . '/activate',
            '/admin-api/users/' . $userId . '/deactivate',
            '/admin-api/users/' . $userId . '/force-logout',
            '/admin-api/users/' . $userId . '/password-reset',
        ];

        foreach ($routes as $route) {
            $this->client->request('POST', $route);
            $this->assertResponseStatusCodeSame(401, "Expected 401 on $route without token");
            $this->assertJson($this->client->getResponse()->getContent());
        }
    }
}
