<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The app's standard SQLite baseline (App\Doctrine\SqlitePragmaMiddleware), applied to every real
 * connection via DBAL middleware autoconfiguration. Pins the actual PRAGMA values a live connection
 * reports — not just that the middleware class exists — since a wiring mistake (e.g. the bundle no
 * longer autoconfiguring `Doctrine\DBAL\Driver\Middleware` implementations) would otherwise silently
 * leave every connection back on SQLite's un-tuned defaults.
 */
final class SqlitePragmaMiddlewareTest extends KernelTestCase
{
    public function testConnectionAppliesTheStandardSqlitePragmas(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        self::assertSame('wal', strtolower((string) $conn->fetchOne('PRAGMA journal_mode')));
        self::assertSame(5000, (int) $conn->fetchOne('PRAGMA busy_timeout'));
        self::assertSame(1, (int) $conn->fetchOne('PRAGMA foreign_keys'));
        self::assertSame('normal', strtolower((string) $conn->fetchOne('PRAGMA locking_mode')));
        self::assertSame(2, (int) $conn->fetchOne('PRAGMA synchronous')); // 2 = FULL
    }

    public function testForeignKeyEnforcementActuallyCascadesOnDelete(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        // account_lockouts declares `user_id ... ON DELETE CASCADE` against `user`. Prove the
        // pragma isn't just reported as on but actually enforced by the SQLite engine: insert a
        // user + a dependent lockout row, hard-delete the user, and confirm the lockout row is
        // gone too rather than left orphaned.
        $conn->executeStatement(
            'INSERT INTO "user" (email, password, name, roles, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['pragma-fk-test@example.com', 'x', 'x', '[]', 'active', '2026-01-01 00:00:00'],
        );
        $userId = (int) $conn->lastInsertId();

        $conn->executeStatement(
            'INSERT INTO account_lockouts (locked_until, user_id) VALUES (?, ?)',
            ['2099-01-01 00:00:00', $userId],
        );

        $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

        $remaining = $conn->fetchOne('SELECT COUNT(*) FROM account_lockouts WHERE user_id = ?', [$userId]);
        self::assertSame(0, (int) $remaining, 'ON DELETE CASCADE must actually fire with foreign_keys=ON.');
    }
}
