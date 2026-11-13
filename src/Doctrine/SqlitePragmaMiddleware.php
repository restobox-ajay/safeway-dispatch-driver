<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * The app's standard SQLite connection baseline (ADR-001: SQLite-only). Applied to every real
 * connection via DBAL's middleware mechanism (autoconfigured — DoctrineBundle tags any service
 * implementing `Middleware` as `doctrine.middleware`), so dev and prod get it exactly like every
 * other project, without relying on any one caller to remember it:
 *
 *   - `journal_mode = WAL` + `busy_timeout = 5000` — readers don't block writers and a brief
 *     writer overlap retries instead of failing outright ("database is locked").
 *   - `foreign_keys = ON` — SQLite ignores declared FOREIGN KEY constraints (including the
 *     satellite tables' `ON DELETE CASCADE`) unless this is set on every connection; it is not
 *     persisted in the database file.
 *   - `locking_mode = NORMAL` / `synchronous = FULL` — already SQLite's own defaults; set
 *     explicitly so the baseline is self-documenting and doesn't drift if that ever changes.
 *
 * The pragma list itself lives in {@see SqliteConnectionBaseline}, shared with the session handler's separate
 * PDO connection ({@see SqlitePdoFactory}), which this middleware cannot reach.
 *
 * Fails loud, not silent, for anything that connects but is NOT actually PDO SQLite: this app has
 * raw SQLite-specific DDL and DBAL-only tables throughout (migrations, `login_attempts`,
 * `endpoint_rate_limits`, session storage), so a MySQL/PostgreSQL `DATABASE_URL` would otherwise
 * either fail confusingly deep in a migration or — worse — half-work with none of this baseline
 * applied. Better to refuse at the first connection, with a message that says exactly why.
 */
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): ConnectionInterface
            {
                $connection = parent::connect($params);

                $native = $connection->getNativeConnection();
                if (!$native instanceof \PDO || $native->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                    throw new \LogicException(
                        'This application is SQLite-only (ADR-001 / ADR-013). DATABASE_URL must use the '
                        . '"sqlite:///" scheme — do not point it at MySQL, MariaDB, PostgreSQL, or any other '
                        . 'engine; the schema, migrations, and DBAL-only tables all assume SQLite.',
                    );
                }

                return new class ($connection) extends AbstractConnectionMiddleware {
                    public function __construct(ConnectionInterface $wrappedConnection)
                    {
                        parent::__construct($wrappedConnection);

                        foreach (SqliteConnectionBaseline::PRAGMAS as $pragma) {
                            $wrappedConnection->exec($pragma);
                        }
                    }
                };
            }
        };
    }
}
