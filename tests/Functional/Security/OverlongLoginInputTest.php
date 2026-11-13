<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #22: SQLite does not enforce VARCHAR lengths, and a login failure with an over-long "email" is recorded
 * before any throttle or CSRF check runs. Every write must respect the column contract, or an unauthenticated
 * loop of multi-megabyte POSTs fills the single database file.
 */
final class OverlongLoginInputTest extends WebTestCase
{
    private const PREFIX = 'overlong-';

    private function conn(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function cleanup(): void
    {
        $this->conn()->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'overlong-%' OR action LIKE 'overlong-%'");
        $this->conn()->executeStatement("DELETE FROM login_attempts WHERE email LIKE 'overlong-%'");
        $this->conn()->executeStatement('DELETE FROM endpoint_rate_limits');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAnOverlongLoginEmailIsStoredWithinTheColumnLimits(): void
    {
        $client = static::createClient();
        $this->cleanup();
        $huge = self::PREFIX . str_repeat('a', 6000) . '@example.com';

        foreach (['/staff/login', '/admin/login'] as $path) {
            $client->request('POST', $path, ['email' => $huge, 'password' => 'x']);
        }

        $actors = $this->conn()->fetchFirstColumn("SELECT actor FROM audit_log WHERE actor LIKE 'overlong-%'");
        self::assertCount(2, $actors, 'the failures are still audited');
        foreach ($actors as $actor) {
            self::assertLessThanOrEqual(255, mb_strlen($actor), 'audit_log.actor is VARCHAR(255)');
        }

        $emails = $this->conn()->fetchFirstColumn("SELECT email FROM login_attempts WHERE email LIKE 'overlong-%'");
        self::assertNotEmpty($emails, 'the failures still count toward the per-IP throttle');
        foreach ($emails as $email) {
            self::assertLessThanOrEqual(254, mb_strlen($email), 'login_attempts.email is VARCHAR(254)');
        }
    }

    public function testTheAuditSinkEnforcesItsColumnContractOnBothWritePaths(): void
    {
        self::bootKernel();
        $this->cleanup();
        $logger = self::getContainer()->get(AuditLogger::class);
        $long = str_repeat('x', 100_000);

        $logger->log(self::PREFIX . $long, 'user', '1.2.3.4' . $long, 'overlong-direct' . $long, 'failure', $long);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $logger->logDeferred(self::PREFIX . 'deferred' . $long, 'admin', '5.6.7.8' . $long, 'overlong-deferred' . $long, 'success', $long);
        $em->flush();

        $rows = $this->conn()->fetchAllAssociative("SELECT actor, ip, action, context FROM audit_log WHERE action LIKE 'overlong-%'");
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(255, mb_strlen($row['actor']));
            self::assertLessThanOrEqual(45, mb_strlen($row['ip']));
            self::assertLessThanOrEqual(100, mb_strlen($row['action']));
            // context is internal (never request data) and is kept whole: cutting it could hide part of an
            // audited change, e.g. the end of a long IP list in admin.config_update (review of #22).
            self::assertSame(100_000, mb_strlen((string) $row['context']));
        }
    }
}
