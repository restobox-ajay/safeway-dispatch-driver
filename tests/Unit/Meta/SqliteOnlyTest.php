<?php

declare(strict_types=1);

namespace App\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This project is SQLite-only (ADR-001 / ADR-013). A PostgreSQL DATABASE_URL has crept back into
 * .env more than once, and the suite could not see it because .env.test and .env.acceptance both
 * override DATABASE_URL with SQLite — so dev and prod pointed at a database that isn't there while
 * every test stayed green. These tests read the FILES, so they fail on exactly that drift.
 */
final class SqliteOnlyTest extends TestCase
{
    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** The effective value is the LAST assignment dotenv sees, which is what must be SQLite. */
    private static function effectiveDatabaseUrl(string $envFile): ?string
    {
        $path = self::projectDir() . '/' . $envFile;
        if (!is_file($path)) {
            return null;
        }

        $found = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*DATABASE_URL\s*=\s*"?([^"\r\n]*)"?/', $line, $m) === 1) {
                $found = $m[1];
            }
        }

        return $found;
    }

    /** @return array<string,array{0:string}> */
    public static function envFiles(): array
    {
        return [
            '.env'            => ['.env'],
            '.env.dev'        => ['.env.dev'],
            '.env.test'       => ['.env.test'],
            '.env.acceptance' => ['.env.acceptance'],
        ];
    }

    #[DataProvider('envFiles')]
    public function testDatabaseUrlIsSqliteInEveryEnvFile(string $envFile): void
    {
        $url = self::effectiveDatabaseUrl($envFile);

        if ($url === null) {
            // No DATABASE_URL in this file at all: it inherits .env, which this same test pins.
            self::assertTrue(true);

            return;
        }

        self::assertStringStartsWith(
            'sqlite:',
            $url,
            sprintf('%s sets a non-SQLite DATABASE_URL (%s).', $envFile, $url),
        );
    }

    /** `.env` is the fallback for dev and prod, so it is the one that actually bites. */
    public function testRootEnvIsSqlite(): void
    {
        $url = self::effectiveDatabaseUrl('.env');

        self::assertNotNull($url, '.env must define DATABASE_URL — dev and prod fall back to it.');
        self::assertStringStartsWith('sqlite:', $url);
    }

    /** Keeps Flex from writing Docker Compose files into a project that does not use Docker. */
    public function testFlexDockerRecipesAreOptedOut(): void
    {
        $composer = json_decode(
            (string) file_get_contents(self::projectDir() . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertFalse(
            $composer['extra']['symfony']['docker'] ?? null,
            'composer.json must set extra.symfony.docker to false.',
        );
    }

    /** Docker is not used here; the compose files were removed and must not reappear. */
    public function testNoComposeFilesExist(): void
    {
        foreach (['compose.yaml', 'compose.override.yaml', 'docker-compose.yml', 'Dockerfile'] as $file) {
            self::assertFileDoesNotExist(
                self::projectDir() . '/' . $file,
                $file . ' is back. This project does not use Docker.',
            );
        }
    }
}
