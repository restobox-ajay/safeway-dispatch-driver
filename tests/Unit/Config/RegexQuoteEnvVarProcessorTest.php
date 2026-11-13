<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\RegexQuoteEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Audit #1: trusted_hosts must match the real hosts exactly. A lookalike made by replacing the dots
 * (registrable by anyone) would otherwise pass, and reset / magic-link / invite emails are built on the
 * request's host.
 */
final class RegexQuoteEnvVarProcessorTest extends TestCase
{
    /** The pattern Symfony builds from one trusted_hosts entry (Request::setTrustedHosts: '{%s}i'). */
    private static function trusted(string $entry, string $host): bool
    {
        return preg_match('{' . $entry . '}i', $host) === 1;
    }

    public function testOnlyTheRealHostMatches(): void
    {
        $domain = (new RegexQuoteEnvVarProcessor())->getEnv('regex_quote', 'X', static fn () => 'driver.fvstexpress-app.dev.rp017.webhelplogin.com');
        $entry = '^' . $domain . '$';

        $this->assertTrue(self::trusted($entry, 'driver.fvstexpress-app.dev.rp017.webhelplogin.com'));
        $this->assertTrue(self::trusted($entry, 'DRIVER.fvstexpress-app.dev.rp017.webhelplogin.com'), 'host names are case-insensitive');
        $this->assertFalse(self::trusted($entry, 'driver-fvstexpress-app-dev-rp017-webhelplogin.com'));
        $this->assertFalse(self::trusted($entry, 'driverXfvstexpress-app.dev.rp017.webhelplogin.com'));
        $this->assertFalse(self::trusted($entry, 'evil.driver.fvstexpress-app.dev.rp017.webhelplogin.com'));
    }

    public function testEveryTrustedHostPatternIsQuoted(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/framework.yaml');
        $hosts = $config['framework']['trusted_hosts'];

        $this->assertCount(3, $hosts);
        foreach ($hosts as $entry) {
            $this->assertMatchesRegularExpression('/^\^%env\(regex_quote:[^)]+\)%\$$/', $entry, 'unquoted: a "." would match any character');
        }
    }
}
