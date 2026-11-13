<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Helpers for the phpLiteAdmin console credential (ADR-053).
 *
 * The credential is an opaque, randomly generated token. It is NOT derived from the admin id, the
 * session, or any secret: it is unguessable on its own, stored server-side only as a hash, and looked
 * up per request by the gateway (public/db-admin.php). That is what lets it expire and be revoked —
 * there is a row to delete — unlike a deterministic signed cookie, which is the same value forever.
 *
 * Kept as small pure functions so the mint controller and the standalone gateway share them: the
 * gateway runs OUTSIDE the Symfony kernel, so anything it needs must work without the container.
 */
final class ConsoleCookie
{
    public const COOKIE_NAME = 'db_console';

    /** Default console window (minutes) when db_console.window_minutes is unset/invalid. */
    public const DEFAULT_WINDOW_MINUTES = 30;

    /** Config key holding the kill-switch deadline as a UNIX timestamp; absent/0 means OFF. */
    public const ENABLED_UNTIL_KEY = 'db_console.enabled_until';

    /** Config key holding the console window in minutes. */
    public const WINDOW_MINUTES_KEY = 'db_console.window_minutes';

    /** Console window in seconds, from a minutes value; falls back to the default when unusable. */
    public static function windowSeconds(int|string|null $minutes): int
    {
        $minutes = (int) $minutes;

        return ($minutes > 0 ? $minutes : self::DEFAULT_WINDOW_MINUTES) * 60;
    }

    /** A fresh 256-bit opaque token (64 hex chars). This raw value is handed to the client and nothing else. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The stored form of a token. Only the hash is persisted, so a database leak does not yield a
     * usable credential. A fixed-length hash of a random 256-bit token needs no constant-time compare
     * — it is not a low-entropy secret, and the lookup is an indexed equality match, not a comparison.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a Doctrine SQLite DATABASE_URL to a filesystem path, the way the gateway needs it.
     *
     * The gateway runs outside the kernel, so it cannot ask Doctrine where the database is; it has to
     * read DATABASE_URL and resolve it by hand. Doing that HERE — from the same value the kernel uses
     * — is what stops the gateway and the app from ever disagreeing about the path.
     *
     * Dotenv populates $_ENV['DATABASE_URL'] with the raw value; it does NOT expand
     * %kernel.project_dir% or %kernel.environment% (those are container parameters, and Doctrine only
     * sees them resolved because doctrine.yaml routes DATABASE_URL through `env(resolve:...)`, which
     * runs inside the DI container), so this substitutes both by hand.
     *
     * Returns null for anything that is not a concrete sqlite file path — a non-sqlite driver,
     * :memory:, or empty — which the caller treats as "no database" and fails closed.
     */
    public static function sqlitePath(string $databaseUrl, string $projectDir, string $environment = 'dev'): ?string
    {
        if (!str_starts_with($databaseUrl, 'sqlite://')) {
            return null;
        }

        $path = substr($databaseUrl, \strlen('sqlite://'));
        $path = explode('?', $path, 2)[0];         // drop any ?query the DSN may carry
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);              // sqlite:///<path> — the third slash is a separator
        }
        $path = str_replace(
            ['%kernel.project_dir%', '%kernel.environment%'],
            [$projectDir, $environment],
            $path,
        );

        if ($path === '' || $path === ':memory:') {
            return null;
        }

        // A path with no placeholder may still be project-relative; anchor it like the kernel would.
        // A Windows drive path (C:\… or C:/…) is already absolute.
        if (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[\\\\/]#', $path) !== 1) {
            $path = $projectDir . '/' . $path;
        }

        return $path;
    }
}
