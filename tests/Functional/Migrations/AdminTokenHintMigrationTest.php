<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261001120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #39 / ADR-064: the migration that adds token_hint also revokes the tokens of admins who were ALREADY
 * inactive when it runs. Before this release a deactivated/soft-deleted admin's tokens were only rejected while
 * the account stayed inactive, so without the backfill reactivating such an admin after deploy would revive them.
 * Runs the real migration's SQL against a scratch database holding pre-migration data.
 */
final class AdminTokenHintMigrationTest extends TestCase
{
    private string $file;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/admin-token-hint-' . bin2hex(random_bytes(4)) . '.db';
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);

        // The pre-migration shape (Version20260927120000), trimmed to the columns that matter here.
        $this->conn->executeStatement("CREATE TABLE admin (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, status VARCHAR(20) DEFAULT 'active' NOT NULL)");
        $this->conn->executeStatement('CREATE TABLE admin_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_id INTEGER NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->conn->executeStatement("INSERT INTO admin (id, email, status) VALUES (1, 'live@example.com', 'active'), (2, 'gone@example.com', 'inactive')");
        $this->conn->executeStatement("INSERT INTO admin_access_tokens (id, admin_id, name, token_hash, revoked_at, created_at) VALUES
            (1, 1, 'live admin token', 'h1', NULL, '2026-01-01 00:00:00'),
            (2, 2, 'deactivated admin token', 'h2', NULL, '2026-01-01 00:00:00'),
            (3, 2, 'already revoked', 'h3', '2026-02-02 02:02:02', '2026-01-01 00:00:00')");
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        @unlink($this->file);
    }

    private function migrateUp(): void
    {
        // migrations/ is loaded by doctrine-migrations' own finder, not Composer's autoloader.
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261001120000.php';
        $migration = new Version20261001120000($this->conn, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->conn->executeStatement($query->getStatement());
        }
    }

    public function testTokensOfAlreadyInactiveAdminsAreRevokedAndNothingElseChanges(): void
    {
        $this->migrateUp();

        $rows = $this->conn->fetchAllAssociativeIndexed('SELECT id, revoked_at, token_hint FROM admin_access_tokens ORDER BY id');

        self::assertNull($rows[1]['revoked_at'], 'an active admin keeps its working token');
        self::assertNotNull($rows[2]['revoked_at'], 'a deactivated admin\'s token must be revoked, so reactivation cannot revive it');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $rows[2]['revoked_at'], 'stored in the same format Doctrine reads');
        self::assertSame('2026-02-02 02:02:02', $rows[3]['revoked_at'], 'an earlier revocation time is kept');
        self::assertNull($rows[1]['token_hint'], 'existing tokens have no recorded hint');
    }
}
