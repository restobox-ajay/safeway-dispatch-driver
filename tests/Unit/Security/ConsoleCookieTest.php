<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ConsoleCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-053. The db-console credential and the path resolution the gateway depends on.
 *
 * sqlitePath() is the piece worth pinning hardest: the gateway runs outside the kernel and cannot ask
 * Doctrine where the database is, so if this disagrees with DATABASE_URL the console silently denies
 * everyone (or, worse, opens a different database).
 */
final class ConsoleCookieTest extends TestCase
{
    public function testTokenIsA256BitHexValueAndNeverRepeats(): void
    {
        $a = ConsoleCookie::generateToken();
        $b = ConsoleCookie::generateToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b, 'each mint must produce a fresh token');
    }

    public function testOnlyTheHashIsStorable(): void
    {
        $token = ConsoleCookie::generateToken();
        $hash = ConsoleCookie::hashToken($token);

        self::assertSame(hash('sha256', $token), $hash);
        self::assertNotSame($token, $hash, 'the raw token must never be the stored form');
        self::assertSame($hash, ConsoleCookie::hashToken($token), 'hashing must be deterministic');
    }

    /** @return array<string,array{0:int|string|null,1:int}> */
    public static function windows(): array
    {
        return [
            'configured'      => [45, 45 * 60],
            'numeric string'  => ['10', 10 * 60],
            'null'            => [null, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'zero'            => [0, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'negative'        => [-5, ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
            'non-numeric'     => ['abc', ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60],
        ];
    }

    #[DataProvider('windows')]
    public function testWindowSecondsNeverYieldsANonPositiveWindow(int|string|null $minutes, int $expected): void
    {
        // A zero/negative window would expire every session the instant it is minted.
        self::assertSame($expected, ConsoleCookie::windowSeconds($minutes));
    }

    public function testSqlitePathResolvesTheProjectConvention(): void
    {
        self::assertSame(
            '/srv/app/var/data_prod.db',
            ConsoleCookie::sqlitePath(
                'sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db',
                '/srv/app',
                'prod',
            ),
        );
    }

    public function testSqlitePathHandlesAbsoluteRelativeAndQueryForms(): void
    {
        // An absolute path with no placeholders.
        self::assertSame(
            '/var/lib/app/data.sqlite',
            ConsoleCookie::sqlitePath('sqlite:////var/lib/app/data.sqlite', '/srv/app'),
        );

        // Project-relative, anchored like the kernel would.
        self::assertSame(
            '/srv/app/var/data/data.sqlite',
            ConsoleCookie::sqlitePath('sqlite://var/data/data.sqlite', '/srv/app'),
        );

        // A ?query on the DSN is not part of the path.
        self::assertSame(
            '/srv/app/var/data_dev.db',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data_dev.db?cache=shared', '/srv/app'),
        );
    }

    /** @return array<string,array{0:string}> */
    public static function nonFilePaths(): array
    {
        return [
            'postgres'  => ['postgresql://app:pw@127.0.0.1:5432/app'],
            'mysql'     => ['mysql://app:pw@127.0.0.1:3306/app'],
            'in-memory' => ['sqlite:///:memory:'],
            'empty dsn' => [''],
            'empty path' => ['sqlite:///'],
        ];
    }

    #[DataProvider('nonFilePaths')]
    public function testSqlitePathReturnsNullSoTheGatewayFailsClosed(string $dsn): void
    {
        self::assertNull(
            ConsoleCookie::sqlitePath($dsn, '/srv/app'),
            'anything that is not a concrete sqlite file must resolve to null so the gateway denies',
        );
    }
}
