<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The one definition of the app's SQLite connection baseline (ADR-056 / ADR-061), shared by EVERY way the
 * app opens the database so they cannot drift:
 *   - Doctrine's DBAL connections, via {@see SqlitePragmaMiddleware};
 *   - the session handler's own separate PDO connection, via {@see SqlitePdoFactory}
 *     (PdoSessionHandler does not use Doctrine, so a DBAL middleware never touches it).
 *
 * These are PER-CONNECTION settings (except the WAL mode, which is then stored in the database file), so
 * each new connection must run them. Everything here is valid on SQLite 3.26.0, the production version.
 */
final class SqliteConnectionBaseline
{
    /** @var list<string> */
    public const PRAGMAS = [
        'PRAGMA journal_mode = WAL',
        'PRAGMA busy_timeout = 5000',
        'PRAGMA foreign_keys = ON',
        'PRAGMA locking_mode = NORMAL',
        'PRAGMA synchronous = FULL',
    ];

    public static function applyTo(\PDO $pdo): void
    {
        foreach (self::PRAGMAS as $pragma) {
            $pdo->exec($pragma);
        }
    }
}
