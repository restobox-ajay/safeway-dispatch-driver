<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * FEATURE-147 / ADR-048 — the unified app:prune harness end-to-end over a real container + SQLite,
 * exercising every registered pruner (core + the AuthMagicLink/AuthWebhook bundle pruners, both
 * registered in the test kernel).
 *
 * Consolidation, not weakening: every scenario from the two DELETED legacy tests is carried here.
 * old → new mapping:
 *   AuditLogPruneCommandTest::testPruneDeletesOldRows            → testAuditLogOldRowsPrunedRecentSurvive
 *   AuditLogPruneCommandTest::testPruneDoesNotDeleteRecentRows   → testAuditLogOldRowsPrunedRecentSurvive
 *   AuditLogPruneCommandTest::testCommandOutputsDeletedCount     → testAuditLogOldRowsPrunedRecentSurvive (count line)
 *   AuditLogPruneCommandTest::testRetentionDaysDefaultIs90       → testAuditLogRetentionDefaultIs90
 *   MaintenancePruneCommandTest::testPruneRemovesExpiredOrUsedTokensButKeepsValid
 *                                                                → testExpiredOrUsedTokensPrunedValidSurvive
 *   MaintenancePruneCommandTest::testPruneRemovesDeadUserSessionsButKeepsLive
 *                                                                → testDeadUserSessionsPrunedLiveSurvive
 *   MaintenancePruneCommandTest::testPruneNeverTouchesLoginHistory
 *                                                                → testNeverTouchesLoginHistory
 *   MaintenancePruneCommandTest::testCommandReportsPerTableCounts→ testReportsPerTableCounts
 *   MaintenancePruneCommandTest::testCommandIsIdempotent         → testIdempotentSecondRunReportsZero
 * New coverage: dry-run leaves rows intact + writes no audit row; invitation grace semantics; webhook
 * attemptedAt cutoff; audit row written on a real deleting run; --only filter.
 */
final class PruneCommandTest extends KernelTestCase
{
    private const PREFIX = 'prune147-';

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (['password_reset_tokens', 'admin_password_reset_tokens', 'magic_link_tokens', 'invitations'] as $table) {
            $this->conn->executeStatement("DELETE FROM {$table} WHERE email LIKE :p", ['p' => self::PREFIX . '%']);
        }
        $this->conn->executeStatement('DELETE FROM user_sessions WHERE session_id LIKE :p', ['p' => self::PREFIX . '%']);
        $this->conn->executeStatement('DELETE FROM admin_sessions WHERE session_id LIKE :p', ['p' => self::PREFIX . '%']);
        $this->conn->executeStatement('DELETE FROM sessions WHERE sess_id LIKE :p', ['p' => self::PREFIX . '%']);
        $this->conn->executeStatement('DELETE FROM login_history WHERE fingerprint = :f', ['f' => self::PREFIX . 'fp']);
        $this->conn->executeStatement('DELETE FROM webhook_delivery WHERE event_type = :e', ['e' => self::PREFIX . 'evt']);
        $this->conn->executeStatement('DELETE FROM audit_log WHERE actor LIKE :p', ['p' => self::PREFIX . '%']);
        $this->conn->executeStatement('DELETE FROM audit_log WHERE action = :a', ['a' => 'maintenance.prune']);
        // Keep retention defaults in force.
        $this->conn->executeStatement("DELETE FROM config WHERE config_key IN ('audit_log.retention_days', 'invitation.retention_days', 'webhook.delivery_retention_days')");
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:prune'));
    }

    /** Drain everything currently prunable so a subsequent count is deterministic across the suite. */
    private function drain(): void
    {
        $this->tester()->execute([]);
        $this->conn->executeStatement('DELETE FROM audit_log WHERE action = :a', ['a' => 'maintenance.prune']);
    }

    private function insertAuditLog(string $actor, \DateTimeImmutable $createdAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO audit_log (actor, actor_type, ip, action, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$actor, 'user', '127.0.0.1', 'login', 'success', $createdAt->format('Y-m-d H:i:s')]
        );
    }

    private function insertToken(string $table, string $suffix, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $usedAt): void
    {
        $this->conn->executeStatement(
            "INSERT INTO {$table} (email, token_hash, expires_at, used_at, created_at) VALUES (?, ?, ?, ?, ?)",
            [
                self::PREFIX . $suffix . '@example.com',
                hash('sha256', $table . $suffix . self::PREFIX),
                $expiresAt->format('Y-m-d H:i:s'),
                $usedAt?->format('Y-m-d H:i:s'),
                (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function seedTokenTable(string $table): void
    {
        $future = new \DateTimeImmutable('+1 day');
        $past = new \DateTimeImmutable('-1 day');
        $this->insertToken($table, 'expired', $past, null);                                // expired, unused -> pruned
        $this->insertToken($table, 'used', $future, new \DateTimeImmutable('-1 hour'));    // used, not expired -> pruned
        $this->insertToken($table, 'valid', $future, null);                                // valid -> survives
    }

    private function countRows(string $table): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE email LIKE ?",
            [self::PREFIX . '%']
        );
    }

    private function insertUserSession(string $suffix): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'INSERT INTO user_sessions (session_id, user_id, ip, user_agent, created_at, last_active_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::PREFIX . $suffix, 4242, '127.0.0.1', 'phpunit', $now, $now]
        );
    }

    /**
     * A `sessions` row exactly as PdoSessionHandler writes it: sess_time = last write, sess_lifetime = the
     * ABSOLUTE expiry timestamp (time() + ttl), not a duration (issue #36 — duration-shaped seeds masked a
     * predicate that never pruned anything).
     */
    private function insertSessionRow(string $suffix, int $sessTime, int $sessLifetime): void
    {
        $this->conn->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [self::PREFIX . $suffix, '', $sessTime, $sessLifetime]
        );
    }

    private function userSessionExists(string $suffix): bool
    {
        return (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?', [self::PREFIX . $suffix]);
    }

    private function insertAdminSession(string $suffix): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'INSERT INTO admin_sessions (session_id, admin_id, ip, user_agent, created_at, last_active_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::PREFIX . $suffix, 4242, '127.0.0.1', 'phpunit', $now, $now]
        );
    }

    private function adminSessionExists(string $suffix): bool
    {
        return (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE session_id = ?', [self::PREFIX . $suffix]);
    }

    private function insertInvitation(string $suffix, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $usedAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO invitations (email, token_hash, expires_at, used_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                self::PREFIX . $suffix . '@example.com',
                hash('sha256', 'inv' . $suffix . self::PREFIX),
                $expiresAt->format('Y-m-d H:i:s'),
                $usedAt?->format('Y-m-d H:i:s'),
                (new \DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function invitationExists(string $suffix): bool
    {
        return (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM invitations WHERE email = ?', [self::PREFIX . $suffix . '@example.com']);
    }

    private function insertWebhookDelivery(string $suffix, \DateTimeImmutable $attemptedAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO webhook_delivery (url, event_type, payload, status, attempted_at) VALUES (?, ?, ?, ?, ?)',
            ['https://example.com/' . $suffix, self::PREFIX . 'evt', '{}', 'delivered', $attemptedAt->format('Y-m-d H:i:s')]
        );
    }

    private function webhookCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM webhook_delivery WHERE event_type = ?', [self::PREFIX . 'evt']);
    }

    private function pruneAuditRows(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['maintenance.prune']);
    }

    // --- audit_log (carried from AuditLogPruneCommandTest) ---------------------------------------

    public function testAuditLogOldRowsPrunedRecentSurvive(): void
    {
        $this->insertAuditLog(self::PREFIX . 'old@example.com', (new \DateTimeImmutable())->modify('-100 days'));
        $this->insertAuditLog(self::PREFIX . 'old2@example.com', (new \DateTimeImmutable())->modify('-200 days'));
        $this->insertAuditLog(self::PREFIX . 'recent@example.com', (new \DateTimeImmutable())->modify('-1 day'));

        $tester = $this->tester();
        $exit = $tester->execute(['--only' => ['audit_log']]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('audit_log: 2', $tester->getDisplay());
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE actor = ?', [self::PREFIX . 'old@example.com']));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE actor = ?', [self::PREFIX . 'recent@example.com']), 'recent audit row survives the 90-day default');
    }

    public function testAuditLogRetentionDefaultIs90(): void
    {
        $config = self::getContainer()->get(\App\Service\ConfigService::class);
        $this->assertSame(90, $config->getInt('audit_log.retention_days', 90));
    }

    // --- ephemeral tokens (carried from MaintenancePruneCommandTest) -----------------------------

    public function testExpiredOrUsedTokensPrunedValidSurvive(): void
    {
        foreach (['password_reset_tokens', 'admin_password_reset_tokens', 'magic_link_tokens'] as $table) {
            $this->seedTokenTable($table);
            $this->assertSame(3, $this->countRows($table), "seed for {$table}");
        }

        $exit = $this->tester()->execute([]);
        $this->assertSame(0, $exit);

        foreach (['password_reset_tokens', 'admin_password_reset_tokens', 'magic_link_tokens'] as $table) {
            $this->assertSame(1, $this->countRows($table), "after prune {$table}");
            $remaining = (string) $this->conn->fetchOne("SELECT email FROM {$table} WHERE email LIKE ?", [self::PREFIX . '%']);
            $this->assertSame(self::PREFIX . 'valid@example.com', $remaining);
        }
    }

    public function testDeadUserSessionsPrunedLiveSurvive(): void
    {
        $now = (new \DateTimeImmutable())->getTimestamp();

        $this->insertUserSession('live');
        $this->insertSessionRow('live', $now, $now + 3600);                 // expires in an hour -> survives

        $this->insertUserSession('expired');
        $this->insertSessionRow('expired', $now - 14400, $now - 3600);       // row still present, expired an hour ago -> pruned

        $this->insertUserSession('orphan');                    // no sessions row -> pruned

        $exit = $this->tester()->execute(['--only' => ['user_sessions']]);
        $this->assertSame(0, $exit);

        $this->assertTrue($this->userSessionExists('live'), 'live session survives');
        $this->assertFalse($this->userSessionExists('expired'), 'expired session pruned');
        $this->assertFalse($this->userSessionExists('orphan'), 'orphan session pruned');
    }

    // FEATURE-148 / ADR-049: the admin-side mirror of testDeadUserSessionsPrunedLiveSurvive.
    public function testDeadAdminSessionsPrunedLiveSurvive(): void
    {
        $now = (new \DateTimeImmutable())->getTimestamp();

        $this->insertAdminSession('a-live');
        $this->insertSessionRow('a-live', $now, $now + 3600);           // expires in an hour -> survives

        $this->insertAdminSession('a-expired');
        $this->insertSessionRow('a-expired', $now - 14400, $now - 3600); // row still present, expired an hour ago -> pruned

        $this->insertAdminSession('a-orphan');                   // no sessions row -> pruned

        $tester = $this->tester();
        $exit = $tester->execute(['--only' => ['admin_sessions']]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('admin_sessions: 2', $tester->getDisplay());

        $this->assertTrue($this->adminSessionExists('a-live'), 'live admin session survives');
        $this->assertFalse($this->adminSessionExists('a-expired'), 'expired admin session pruned');
        $this->assertFalse($this->adminSessionExists('a-orphan'), 'orphan admin session pruned');

        // Idempotent: a second run has nothing left to prune for this pruner.
        $second = $this->tester();
        $second->execute(['--only' => ['admin_sessions']]);
        $this->assertStringContainsString('admin_sessions: 0', $second->getDisplay());
        $this->assertTrue($this->adminSessionExists('a-live'), 'live admin session survives the second run');
    }

    // Issue #30: expired rows of the PdoSessionHandler `sessions` table itself. Nothing else guarantees they are
    // deleted: PHP's probabilistic session GC is left to php.ini (Debian/Ubuntu ship gc_probability = 0), so on
    // such a host every visitor that ever started a session left a row forever. sess_lifetime is the absolute
    // expiry (issue #36), so a row is dead once it is in the past.
    public function testExpiredSessionsRowsPrunedLiveSurvive(): void
    {
        $now = (new \DateTimeImmutable())->getTimestamp();
        $this->insertSessionRow('s-live', $now, $now + 3600);           // expires in an hour -> survives
        $this->insertSessionRow('s-edge', $now, $now + 5);              // not yet expired -> survives
        $this->insertSessionRow('s-expired', $now - 14400, $now - 3600); // expired an hour ago -> pruned

        $tester = $this->tester();
        $exit = $tester->execute(['--only' => ['sessions']]);
        $this->assertSame(0, $exit);

        $alive = fn (string $suffix): bool => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM sessions WHERE sess_id = ?', [self::PREFIX . $suffix]);
        $this->assertTrue($alive('s-live'), 'a live session survives');
        $this->assertTrue($alive('s-edge'), 'a session that has not expired yet survives');
        $this->assertFalse($alive('s-expired'), 'an expired session row is pruned');

        $second = $this->tester();
        $second->execute(['--only' => ['sessions']]);
        $this->assertMatchesRegularExpression('/(?<![_a-z])sessions: 0/', $second->getDisplay(), 'idempotent');
    }

    public function testNeverTouchesLoginHistory(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO login_history (user_id, ip, user_agent, fingerprint, created_at) VALUES (?, ?, ?, ?, ?)',
            [4242, '127.0.0.1', 'phpunit', self::PREFIX . 'fp', (new \DateTimeImmutable('-400 days'))->format('Y-m-d H:i:s')]
        );

        $this->tester()->execute([]);

        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM login_history WHERE fingerprint = ?', [self::PREFIX . 'fp']), 'login_history is retained by policy (ADR-020); no pruner touches it');
    }

    public function testReportsPerTableCounts(): void
    {
        $this->drain();
        $this->seedTokenTable('password_reset_tokens'); // exactly 2 prunable (expired + used)

        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('password_reset_tokens: 2', $display);
        $this->assertStringContainsString('admin_password_reset_tokens: 0', $display);
        $this->assertStringContainsString('magic_link_tokens: 0', $display);
        $this->assertStringContainsString('user_sessions: 0', $display);
        $this->assertStringContainsString('admin_sessions: 0', $display);
        $this->assertStringContainsString('invitations: 0', $display);
        $this->assertStringContainsString('webhook_delivery: 0', $display);
        $this->assertMatchesRegularExpression('/(?<![_a-z])sessions: 0/', $display); // not user_/admin_sessions
    }

    public function testIdempotentSecondRunReportsZero(): void
    {
        $this->seedTokenTable('magic_link_tokens');
        $this->insertUserSession('orphan');

        $this->tester()->execute([]);

        $second = $this->tester();
        $exit = $second->execute([]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('magic_link_tokens: 0', $second->getDisplay());
        $this->assertSame(1, $this->countRows('magic_link_tokens'), 'the valid token survives repeated runs');
        $this->assertFalse($this->userSessionExists('orphan'), 'orphan already pruned; stays pruned');
    }

    // --- new: dry-run ----------------------------------------------------------------------------

    public function testDryRunReportsButLeavesRowsIntactAndWritesNoAuditRow(): void
    {
        $this->drain();
        $this->seedTokenTable('password_reset_tokens'); // 2 prunable

        $tester = $this->tester();
        $exit = $tester->execute(['--dry-run' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('dry-run', $display);
        $this->assertStringContainsString('password_reset_tokens: 2', $display);
        $this->assertSame(3, $this->countRows('password_reset_tokens'), 'dry-run deletes nothing');
        $this->assertSame(0, $this->pruneAuditRows(), 'dry-run writes no audit row');
    }

    // --- new: invitation grace semantics ---------------------------------------------------------

    public function testInvitationGraceSemantics(): void
    {
        $future = new \DateTimeImmutable('+7 days');
        $longPast = new \DateTimeImmutable('-40 days');
        $recentPast = new \DateTimeImmutable('-10 days');

        // used 40 days ago -> terminal + past 30d grace -> pruned
        $this->insertInvitation('used-old', $future, $longPast);
        // used yesterday -> terminal but within grace -> survives
        $this->insertInvitation('used-recent', $future, new \DateTimeImmutable('-1 day'));
        // unused & unexpired (created 60d ago) -> not terminal -> survives regardless of age
        $this->insertInvitation('valid', $future, null);
        // expired 40 days ago, unused -> terminal + past grace -> pruned
        $this->insertInvitation('expired-old', $longPast, null);
        // expired 10 days ago, unused -> terminal but within grace -> survives
        $this->insertInvitation('expired-recent', $recentPast, null);

        $tester = $this->tester();
        $exit = $tester->execute(['--only' => ['invitations']]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('invitations: 2', $tester->getDisplay());

        $this->assertFalse($this->invitationExists('used-old'), 'used past grace -> pruned');
        $this->assertTrue($this->invitationExists('used-recent'), 'used within grace -> survives');
        $this->assertTrue($this->invitationExists('valid'), 'still-usable invite -> survives regardless of age');
        $this->assertFalse($this->invitationExists('expired-old'), 'expired past grace -> pruned');
        $this->assertTrue($this->invitationExists('expired-recent'), 'expired within grace -> survives');
    }

    // --- new: webhook attemptedAt cutoff ---------------------------------------------------------

    public function testWebhookDeliveryCutoff(): void
    {
        $this->insertWebhookDelivery('old', new \DateTimeImmutable('-40 days'));   // past 30d -> pruned
        $this->insertWebhookDelivery('recent', new \DateTimeImmutable('-10 days')); // within 30d -> survives

        $tester = $this->tester();
        $exit = $tester->execute(['--only' => ['webhook_delivery']]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('webhook_delivery: 1', $tester->getDisplay());
        $this->assertSame(1, $this->webhookCount(), 'only the row past the retention window is pruned');
    }

    // --- new: audit row on a real deleting run ---------------------------------------------------

    public function testRealDeletingRunWritesExactlyOneAuditRow(): void
    {
        $this->drain();
        $this->seedTokenTable('password_reset_tokens'); // 2 prunable

        $this->tester()->execute([]);

        $this->assertSame(1, $this->pruneAuditRows(), 'a real run that deleted rows writes exactly one maintenance.prune audit row');
        $row = $this->conn->fetchAssociative('SELECT * FROM audit_log WHERE action = ?', ['maintenance.prune']);
        $this->assertSame('success', $row['outcome']);
        $this->assertStringContainsString('password_reset_tokens=2', (string) $row['context']);
    }

    public function testIdleRealRunWritesNoAuditRow(): void
    {
        $this->drain();

        $this->tester()->execute([]);

        $this->assertSame(0, $this->pruneAuditRows(), 'an idle real run deletes nothing and writes no audit row');
    }
}
