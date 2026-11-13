<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Bundle\AuthWebhook\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-105 / review C19: the hard-coded SSRF blocklist. These tests exercise the pure,
 * static core (no DNS, no config) — IP-range membership including the IPv6 embedded-IPv4
 * bypass vectors, and the public-FQDN requirement.
 */
final class SsrfGuardTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function blockedIps(): array
    {
        return [
            // IPv4 (AC1)
            'v4 unspecified 0/8'   => ['0.0.0.0'],
            'v4 loopback 127/8'    => ['127.0.0.1'],
            'v4 private 10/8'      => ['10.1.2.3'],
            'v4 private 172.16/12' => ['172.16.0.1'],
            'v4 private 172.31'    => ['172.31.255.254'],
            'v4 private 192.168'   => ['192.168.1.10'],
            'v4 link-local/meta'   => ['169.254.169.254'],
            'v4 cgnat 100.64/10'   => ['100.64.0.1'],
            // IPv6 (AC2)
            'v6 loopback ::1'      => ['::1'],
            'v6 unspecified ::'    => ['::'],
            'v6 link-local fe80'   => ['fe80::1'],
            'v6 unique-local fc00' => ['fc00::1'],
            'v6 unique-local fd00' => ['fd00::1'],
            'v6 multicast ff00'    => ['ff02::1'],
            // Embedded-IPv4 bypass forms (AC3)
            'v6 mapped loopback'   => ['::ffff:127.0.0.1'],
            'v6 mapped metadata'   => ['::ffff:169.254.169.254'],
            '6to4 loopback'        => ['2002:7f00:0001::'],
            'nat64 loopback'       => ['64:ff9b::7f00:1'],
        ];
    }

    #[DataProvider('blockedIps')]
    public function testBlockedIpsAreRejected(string $ip): void
    {
        self::assertTrue(SsrfGuard::isBlockedIp($ip), $ip . ' must be blocked');
    }

    /** @return array<string,array{0:string}> */
    public static function publicIps(): array
    {
        return [
            'public v4'      => ['93.184.216.34'],
            'public v4 dns'  => ['8.8.8.8'],
            'public v6'      => ['2606:4700:4700::1111'],
            // 6to4 wrapping a *public* v4 stays allowed (embedded 93.184.216.34)
            '6to4 public'    => ['2002:5db8:d822::'],
        ];
    }

    #[DataProvider('publicIps')]
    public function testPublicIpsAreAllowed(string $ip): void
    {
        self::assertFalse(SsrfGuard::isBlockedIp($ip), $ip . ' should be allowed');
    }

    /** @return array<string,array{0:string}> */
    public static function nonPublicFqdns(): array
    {
        return [
            'single label localhost' => ['localhost'],
            'single label db'        => ['db'],
            'single label intranet'  => ['intranet'],
            'tld .local'             => ['printer.local'],
            'tld .localdomain'       => ['localhost.localdomain'],
            'tld .localdomain host'  => ['box.localdomain'],
            'tld .internal'          => ['api.internal'],
            'tld .localhost'         => ['app.localhost'],
            'tld .lan'               => ['nas.lan'],
            'tld .corp'              => ['host.corp'],
            'suffix .home.arpa'      => ['router.home.arpa'],
            'empty'                  => [''],
        ];
    }

    #[DataProvider('nonPublicFqdns')]
    public function testNonPublicFqdnsAreRejected(string $host): void
    {
        self::assertFalse(SsrfGuard::isPublicFqdn($host), $host . ' must not count as a public FQDN');
    }

    /** @return array<string,array{0:string}> */
    public static function publicFqdns(): array
    {
        return [
            'com'                => ['hooks.example.com'],
            'co.uk'              => ['api.example.co.uk'],
            'trailing dot'       => ['hooks.example.com.'],
            'io'                 => ['example.io'],
        ];
    }

    #[DataProvider('publicFqdns')]
    public function testPublicFqdnsAreAccepted(string $host): void
    {
        self::assertTrue(SsrfGuard::isPublicFqdn($host), $host . ' should count as a public FQDN');
    }

    /**
     * AC9 rolled up: the exact bypass vectors named in the acceptance criteria are blocked,
     * while a normal public host literal is allowed. evaluate() with blockingEnabled=true is
     * the delivery gate.
     */
    public function testNamedBypassVectorsAreBlockedByEvaluate(): void
    {
        foreach ([
            'http://[::ffff:127.0.0.1]/h',
            'http://[::ffff:169.254.169.254]/h',
            'http://[2002:7f00:0001::]/h',
            'http://[64:ff9b::7f00:1]/h',
            'http://[fd00::1]/h',
            'http://[fe80::1]/h',
            'http://[::1]/h',
            // localhost / localdomain are refused at the name layer (no DNS needed), so the
            // canonical loopback alias localhost.localdomain and any *.localdomain host is blocked
            // regardless of what it resolves to.
            'http://localhost/h',
            'http://localhost.localdomain/h',
            'http://box.localdomain/h',
        ] as $url) {
            self::assertFalse(SsrfGuard::evaluate($url, true)->allowed, $url . ' must be blocked');
        }

        self::assertTrue(SsrfGuard::evaluate('http://93.184.216.34/h', true)->allowed, 'public host allowed');
    }

    public function testNonHttpSchemesRejectedEvenWhenBlockingDisabled(): void
    {
        foreach (['file:///etc/passwd', 'php://filter/resource=/etc/passwd', 'gopher://127.0.0.1:6379/'] as $url) {
            self::assertFalse(
                SsrfGuard::evaluate($url, false)->allowed,
                $url . ' must be rejected even with blocking OFF (scheme guard is unconditional)',
            );
        }
    }

    public function testInternalTargetAllowedWhenBlockingDisabled(): void
    {
        // Switch OFF: an operator opts into internal delivery. Loopback IP literal is now allowed.
        $off = SsrfGuard::evaluate('http://127.0.0.1/hook', false);
        self::assertTrue($off->allowed, 'loopback allowed when blocking disabled');

        $on = SsrfGuard::evaluate('http://127.0.0.1/hook', true);
        self::assertFalse($on->allowed, 'loopback blocked when blocking enabled');
    }

    public function testAllowedIpLiteralIsPinned(): void
    {
        $result = SsrfGuard::evaluate('https://93.184.216.34:8443/hook', true);
        self::assertTrue($result->allowed);
        self::assertSame(['93.184.216.34'], $result->pinnedIps);
        self::assertSame(8443, $result->port);
        self::assertSame('93.184.216.34', $result->host);
    }
}
