<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use App\Doctrine\SqliteMigrationForeignKeyGuard;
use App\Doctrine\SqlitePragmaMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Query\Query;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ADR-061. With foreign_keys ON (the runtime baseline), SQLite's table-rebuild idiom — which Doctrine
 * generates for any column change — silently cascade-deletes child rows when the PARENT is dropped. The guard
 * turns enforcement off for the migration run. The "control" case proves the hazard is real (so the guard
 * is needed), using a scratch database on the very same baseline connection the app uses.
 */
final class SqliteMigrationForeignKeyGuardTest extends KernelTestCase
{
    private string $file;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'fk-guard-') ?: self::fail('no temp file');
        // A connection with EXACTLY the app's baseline (foreign_keys = ON), on a scratch file.
        $this->conn = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->file],
            (new \Doctrine\DBAL\Configuration())->setMiddlewares([new SqlitePragmaMiddleware()]),
        );
        $this->conn->executeStatement('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL)');
        $this->conn->executeStatement('CREATE TABLE satellite (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT fk_u FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE)');
        $this->conn->executeStatement("INSERT INTO \"user\" (id, email) VALUES (1, 'a@example.com')");
        $this->conn->executeStatement('INSERT INTO satellite (user_id) VALUES (1)');
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
    }

    /** The table-rebuild Doctrine emits for an ALTER on SQLite: copy, DROP the original, rename the copy. */
    private function rebuildParent(): void
    {
        $this->conn->executeStatement('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email FROM "user"');
        $this->conn->executeStatement('DROP TABLE "user"');
        $this->conn->executeStatement('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, added INTEGER DEFAULT NULL)');
        $this->conn->executeStatement('INSERT INTO "user" (id, email) SELECT id, email FROM __temp__user');
        $this->conn->executeStatement('DROP TABLE __temp__user');
    }

    private function satellites(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM satellite');
    }

    private function args(): MigrationsEventArgs
    {
        return new MigrationsEventArgs($this->conn, new MigrationPlanList([], 'up'), new MigratorConfiguration());
    }

    public function testControl_RebuildingTheParentWithForeignKeysOnCascadeDeletesTheChildRows(): void
    {
        self::assertSame(1, (int) $this->conn->fetchOne('PRAGMA foreign_keys'), 'the baseline has enforcement on');

        $this->rebuildParent();

        self::assertSame(0, $this->satellites(), 'the hazard is real: the rebuild wiped the child rows');
    }

    public function testTheGuardMakesTheSameRebuildSafeAndRestoresEnforcement(): void
    {
        $guard = new SqliteMigrationForeignKeyGuard();

        $guard->onMigrationsMigrating($this->args());
        self::assertSame(0, (int) $this->conn->fetchOne('PRAGMA foreign_keys'), 'enforcement is off while migrating');

        $this->rebuildParent();
        self::assertSame(1, $this->satellites(), 'child rows survive the parent rebuild');

        $guard->onMigrationsMigrated($this->args());
        self::assertSame(1, (int) $this->conn->fetchOne('PRAGMA foreign_keys'), 'enforcement is back on afterwards');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM "user" WHERE added IS NULL'), 'and the rebuild itself happened');
    }

    public function testItFailsLoudlyIfTheMigrationLeftOrphanedRows(): void
    {
        $guard = new SqliteMigrationForeignKeyGuard();
        $guard->onMigrationsMigrating($this->args());
        $this->conn->executeStatement('INSERT INTO satellite (user_id) VALUES (999)'); // an orphan enforcement would have refused

        try {
            $guard->onMigrationsMigrated($this->args());
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('foreign-key violation', $e->getMessage());
            self::assertStringContainsString('satellite', $e->getMessage());
        }
        self::assertSame(1, (int) $this->conn->fetchOne('PRAGMA foreign_keys'), 'enforcement is restored even when it reports a violation');
    }

    public function testItRefusesAllOrNothingBecauseThePragmaWouldSilentlyDoNothing(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('--all-or-nothing');
            (new SqliteMigrationForeignKeyGuard())->onMigrationsMigrating($this->args());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testItIsRegisteredOnTheRealMigrationEvents(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get('doctrine.dbal.default_connection.event_manager');

        foreach (['onMigrationsMigrating', 'onMigrationsMigrated'] as $event) {
            $instances = array_filter($manager->getListeners($event), static fn (object $l): bool => $l instanceof SqliteMigrationForeignKeyGuard);
            self::assertNotEmpty($instances, "the guard must listen to $event, or migrations would run with foreign keys on");
        }
    }
}
