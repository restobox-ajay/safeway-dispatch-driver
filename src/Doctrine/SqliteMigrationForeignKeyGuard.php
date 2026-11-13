<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Events;

/**
 * Runs migrations with SQLite foreign-key enforcement OFF (ADR-061).
 *
 * The connection baseline turns `foreign_keys` ON (ADR-056), which is right at runtime but dangerous during a
 * migration. SQLite cannot alter most columns in place, so Doctrine's generated SQL REBUILDS a table:
 * create a copy, `DROP TABLE` the original, rename the copy. With enforcement on, `DROP TABLE "user"` is an
 * implicit `DELETE FROM "user"`, which fires every child table's `ON DELETE CASCADE` — the satellite tables
 * (two_factor_settings, account_lockouts, password_meta, user_ip_whitelist) would silently lose every row.
 * Proven on SQLite 3.26.0 (production): rebuilding the parent with FKs ON left `lockouts=0 2fa=0`; OFF kept both.
 *
 * SQLite's documented procedure is: disable enforcement, run the rebuild, `PRAGMA foreign_key_check`,
 * re-enable. The pragma is a silent no-op inside a transaction, so this must run BEFORE migrations open one
 * (`onMigrationsMigrating` fires first in the default mode) — which is also why `--all-or-nothing`, where the
 * transaction is already open, is refused rather than allowed to quietly protect nothing.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrating)]
#[AsDoctrineListener(event: Events::onMigrationsMigrated)]
final class SqliteMigrationForeignKeyGuard
{
    public function onMigrationsMigrating(MigrationsEventArgs $args): void
    {
        $connection = $args->getConnection();

        if ($connection->isTransactionActive()) {
            throw new \LogicException(
                'Run SQLite migrations without --all-or-nothing: foreign keys cannot be disabled inside a transaction, '
                . 'and rebuilding a parent table (e.g. "user") with them on would cascade-delete its satellite tables\' rows.',
            );
        }

        $connection->executeStatement('PRAGMA foreign_keys = OFF');
    }

    public function onMigrationsMigrated(MigrationsEventArgs $args): void
    {
        $connection = $args->getConnection();

        $violations = $connection->fetchAllAssociative('PRAGMA foreign_key_check');
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        if ($violations !== []) {
            throw new \RuntimeException(sprintf(
                'The migrations left %d foreign-key violation(s) (first: table "%s", row %s, parent "%s"). Fix the data or the migration.',
                \count($violations),
                $violations[0]['table'],
                $violations[0]['rowid'] ?? '?',
                $violations[0]['parent'],
            ));
        }
    }
}
