<?php

declare(strict_types=1);

namespace App\Tests\Functional\Meta;

use PHPUnit\Framework\TestCase;

/**
 * Issue #38: the SQL written by `doctrine:migrations:migrate --dry-run --write-sql` for a FRESH database must
 * replay cleanly — it is what the CLAUDE.md SQLite 3.26.0 verification feeds to the real 3.26.0 binary, and what an
 * operator would apply by hand. Migrations that decide "create or not" by introspecting the live database break
 * this: in a dry run nothing executes, so two such migrations both see no table and both emit the CREATE.
 *
 * Runs the real console command in a subprocess against an empty database file, then executes the written SQL
 * against another empty database. Doctrine's own metadata table is not part of the written SQL (only its INSERTs
 * are), so it is created first, exactly as `doctrine:migrations:sync-metadata-storage` would.
 */
final class MigrationDryRunSqlTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migration-dry-run-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheDryRunSqlForAFreshDatabaseReplaysCleanly(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $sqlFile = $this->dir . '/out.sql';

        $process = proc_open(
            ['php', 'bin/console', 'doctrine:migrations:migrate', '--dry-run', '--write-sql=' . $sqlFile, '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
            [
                'APP_ENV'      => 'dev',
                'DATABASE_URL' => 'sqlite:///' . $this->dir . '/source.db',
                'PATH'         => getenv('PATH') ?: '/usr/bin:/bin',
            ],
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, 'the dry run itself failed: ' . $output);
        self::assertFileExists($sqlFile);

        $sql = (string) file_get_contents($sqlFile);
        self::assertStringContainsString('CREATE TABLE', $sql, 'the dry run must have written the schema');

        $replay = new \PDO('sqlite:' . $this->dir . '/replay.db', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $replay->exec('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INTEGER DEFAULT NULL)');

        try {
            $replay->exec($sql);
        } catch (\PDOException $e) {
            self::fail('the written migration SQL does not replay on a fresh database: ' . $e->getMessage());
        }

        self::assertSame(1, (int) $replay->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'webhook_delivery'")->fetchColumn());
    }
}
