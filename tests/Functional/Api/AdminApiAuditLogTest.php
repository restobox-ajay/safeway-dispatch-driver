<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiAuditLogTest extends WebTestCase
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
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apiaudit%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement("DELETE FROM config WHERE config_key = 'login_notifications.enabled'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('apiauditadmin@example.com');
        $admin->setName('API Audit Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne(
            "SELECT id FROM admin WHERE email = 'apiauditadmin@example.com'"
        );

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Audit API Token',
            'token_hash' => $hash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->conn->insert('config', [
            'config_key'   => 'login_notifications.enabled',
            'config_value' => '0',
        ]);

        return $plaintext;
    }

    private function apiGet(string $url, ?string $token = null): void
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->adminToken),
        ]);
    }

    private function insertAuditEntry(string $actor, string $action, string $outcome = 'success', ?string $createdAt = null): void
    {
        $this->conn->insert('audit_log', [
            'actor'      => $actor,
            'actor_type' => 'user',
            'ip'         => '127.0.0.1',
            'action'     => $action,
            'outcome'    => $outcome,
            'created_at' => $createdAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    // AC1: GET /admin-api/audit-log returns paginated JSON audit entries
    public function testAuditLogEndpointReturnsPaginatedJson(): void
    {
        $this->insertAuditEntry('user@example.com', 'login');
        $this->insertAuditEntry('admin@example.com', 'admin.user_create');

        $this->apiGet('/admin-api/audit-log');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertGreaterThanOrEqual(2, $data['meta']['total']);
        $this->assertNotEmpty($data['data']);
        $this->assertArrayHasKey('total_pages', $data['meta']);
        $this->assertArrayHasKey('page', $data['meta']);

        $entry = $data['data'][0];
        $this->assertArrayHasKey('id', $entry);
        $this->assertArrayHasKey('actor', $entry);
        $this->assertArrayHasKey('actor_type', $entry);
        $this->assertArrayHasKey('ip', $entry);
        $this->assertArrayHasKey('action', $entry);
        $this->assertArrayHasKey('outcome', $entry);
        $this->assertArrayHasKey('created_at', $entry);
    }

    // AC2: Filtering by actor, action, and date range via query parameters works
    public function testFilteringByActorActionAndDateRange(): void
    {
        $this->insertAuditEntry('alice@example.com', 'login', 'success', '2024-01-15 10:00:00');
        $this->insertAuditEntry('bob@example.com', 'register', 'success', '2024-01-20 10:00:00');
        $this->insertAuditEntry('alice@example.com', 'password_reset_request', 'success', '2024-02-01 10:00:00');

        // Filter by actor
        $this->apiGet('/admin-api/audit-log?actor=alice');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEquals(2, $data['meta']['total']);

        // Filter by action
        $this->apiGet('/admin-api/audit-log?action=register');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $data['meta']['total']);
        $this->assertEquals('bob@example.com', $data['data'][0]['actor']);

        // Filter by date range
        $this->apiGet('/admin-api/audit-log?date_from=2024-01-01&date_to=2024-01-31');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEquals(2, $data['meta']['total']);
    }

    // C24: An invalid date filter must return 422 (not a 500)
    public function testInvalidDateFilterReturns422(): void
    {
        $this->apiGet('/admin-api/audit-log?date_from=banana');

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('date_from', $data['error']);
    }

    // C24: Valid date filters still work after the parsing change
    public function testValidDateFilterStillWorksAfterHardening(): void
    {
        $this->insertAuditEntry('validdate1@example.com', 'login', 'success', '2024-03-10 10:00:00');
        $this->insertAuditEntry('validdate2@example.com', 'login', 'success', '2025-03-10 10:00:00');

        $this->apiGet('/admin-api/audit-log?date_from=2024-01-01&date_to=2024-12-31');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $data['meta']['total']);
        $this->assertEquals('validdate1@example.com', $data['data'][0]['actor']);
    }

    // AC3: Returns 401 without a valid admin token
    public function testReturns401WithoutValidToken(): void
    {
        $this->client->request('GET', '/admin-api/audit-log');

        $this->assertResponseStatusCodeSame(401);
        $this->assertJson((string) $this->client->getResponse()->getContent());
    }
}
