<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Security\ConsoleCookie;

/**
 * Opens the PDO connection the session handler uses, with the same baseline as Doctrine's connections.
 *
 * PdoSessionHandler opens its own PDO from a DSN — entirely outside Doctrine — so the DBAL middleware never
 * reaches it, and a bare `new PDO('sqlite:…')` runs with none of the baseline (no foreign-key enforcement,
 * default synchronous level, PHP's 60 s busy timeout). Handing the handler a connection built here gives
 * sessions and data identical settings, and — because it is built from the very same DATABASE_URL Doctrine
 * uses — the very same file.
 */
final class SqlitePdoFactory
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    /** @param string $databaseUrl a resolved SQLite URL such as `sqlite:////abs/path/app.db` */
    public function create(string $databaseUrl): \PDO
    {
        $path = ConsoleCookie::sqlitePath($databaseUrl, $this->projectDir, $this->environment);
        if ($path === null) {
            throw new \LogicException('This application is SQLite-only (ADR-001 / ADR-013). The session connection needs a file-backed "sqlite:///" DATABASE_URL.');
        }

        // PdoSessionHandler refuses a PDO whose error mode is not "throw".
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        SqliteConnectionBaseline::applyTo($pdo);

        return $pdo;
    }
}
