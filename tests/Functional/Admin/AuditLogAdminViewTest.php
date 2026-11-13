<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuditLogAdminViewTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = $this->em->getConnection();

        $this->cleanup();

        $this->createTestAdmin('auditview-admin@example.com', 'Audit View Admin');
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
                "DELETE FROM audit_log WHERE actor LIKE 'auditview-%@example.com'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'auditview-admin@example.com']);
            if ($admin !== null) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    private function insertAuditEntry(
        string $actor,
        string $actorType = 'user',
        string $ip = '127.0.0.1',
        string $action = 'login',
        string $outcome = 'success',
        string $createdAt = '2026-01-15 10:00:00'
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO audit_log (actor, actor_type, ip, action, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$actor, $actorType, $ip, $action, $outcome, $createdAt]
        );
    }

    // AC1: GET /admin/audit-log renders a paginated list of audit events
    public function testAuditLogPageRendersPaginatedList(): void
    {
        $this->insertAuditEntry('auditview-user1@example.com');
        $this->insertAuditEntry('auditview-user2@example.com');

        $this->loginAsAdmin('auditview-admin@example.com');
        // Filter by 'auditview-' to isolate test entries from other test suites' audit data
        $this->client->request('GET', '/admin/audit-log?actor=auditview-user');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<table', $content);
        $this->assertStringContainsString('auditview-user1@example.com', $content);
        $this->assertStringContainsString('auditview-user2@example.com', $content);
    }

    // AC2: Filtering by actor narrows the results
    public function testFilteringByActorNarrowsResults(): void
    {
        $this->insertAuditEntry('auditview-alpha@example.com', 'user', '10.0.0.1', 'login', 'success');
        $this->insertAuditEntry('auditview-beta@example.com', 'user', '10.0.0.2', 'register', 'success');

        $this->loginAsAdmin('auditview-admin@example.com');
        $this->client->request('GET', '/admin/audit-log?actor=auditview-alpha');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('auditview-alpha@example.com', $content);
        $this->assertStringNotContainsString('auditview-beta@example.com', $content);
    }

    // AC2: Filtering by action type narrows the results
    public function testFilteringByActionNarrowsResults(): void
    {
        $this->insertAuditEntry('auditview-c@example.com', 'user', '10.0.0.1', 'login', 'success');
        $this->insertAuditEntry('auditview-d@example.com', 'user', '10.0.0.2', 'register', 'success');

        $this->loginAsAdmin('auditview-admin@example.com');
        // Filter by both action and actor prefix to isolate from other test data
        $this->client->request('GET', '/admin/audit-log?action=register&actor=auditview-');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('auditview-d@example.com', $content);
        $this->assertStringNotContainsString('auditview-c@example.com', $content);
    }

    // AC2: Filtering by date range narrows the results
    public function testFilteringByDateRangeNarrowsResults(): void
    {
        $this->insertAuditEntry('auditview-e@example.com', 'user', '10.0.0.1', 'login', 'success', '2025-01-01 10:00:00');
        $this->insertAuditEntry('auditview-f@example.com', 'user', '10.0.0.2', 'login', 'success', '2026-06-01 10:00:00');

        $this->loginAsAdmin('auditview-admin@example.com');
        // Filter by actor prefix + date range
        $this->client->request('GET', '/admin/audit-log?actor=auditview-&date_from=2026-01-01&date_to=2026-12-31');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('auditview-f@example.com', $content);
        $this->assertStringNotContainsString('auditview-e@example.com', $content);
    }

    // C24: An invalid date filter must be handled gracefully (HTTP 200, not a 500)
    public function testInvalidDateFilterDoesNotError(): void
    {
        $this->insertAuditEntry('auditview-baddate@example.com', 'user', '10.0.0.1', 'login', 'success');

        $this->loginAsAdmin('auditview-admin@example.com');
        $this->client->request('GET', '/admin/audit-log?actor=auditview-baddate&date_from=banana');

        // Must not 500; the page renders and flags the bad input.
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('filter-error', $content);
        // The valid actor filter is still applied, so the entry is listed.
        $this->assertStringContainsString('auditview-baddate@example.com', $content);
    }

    // AC3: Only admin/superadmin can access; unauthenticated redirected
    public function testUnauthenticatedAccessRedirectsToAdminLogin(): void
    {
        $this->client->request('GET', '/admin/audit-log');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/admin/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    // AC4: Each entry shows actor, IP, action, outcome, and timestamp
    public function testEachEntryShowsRequiredFields(): void
    {
        $this->insertAuditEntry(
            'auditview-g@example.com',
            'user',
            '192.168.1.100',
            'login',
            'success',
            '2026-03-15 14:30:00'
        );

        $this->loginAsAdmin('auditview-admin@example.com');
        // Filter by actor to ensure this specific entry is visible on page 1
        $this->client->request('GET', '/admin/audit-log?actor=auditview-g');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('auditview-g@example.com', $content);
        $this->assertStringContainsString('192.168.1.100', $content);
        $this->assertStringContainsString('login', $content);
        $this->assertStringContainsString('success', $content);
        $this->assertStringContainsString('2026-03-15', $content);
    }
}
