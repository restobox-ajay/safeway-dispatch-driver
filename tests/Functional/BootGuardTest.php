<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * FEATURE-131 (review C17 / ADR-029): the app must FAIL FAST at boot unless DEFAULT_URI is a
 * valid absolute URL and both ADMIN_DOMAIN and APP_DOMAIN are set. This guarantees no empty
 * value can reach framework.trusted_hosts (an empty pattern would match every host and silently
 * disable host-header protection).
 *
 * Booting with a deliberately-broken host config would poison the shared in-process kernel, so
 * this is exercised out-of-process via bin/console. A real env var overrides the .env.test
 * default (Symfony Dotenv does not override real env vars), so passing e.g. ADMIN_DOMAIN='' makes
 * the guard see an empty value.
 */
final class BootGuardTest extends TestCase
{
    private static function runConsole(array $envOverrides): array
    {
        $projectDir = \dirname(__DIR__, 2);
        $env = '';
        foreach ($envOverrides as $name => $value) {
            $env .= sprintf('%s=%s ', $name, escapeshellarg($value));
        }
        // 'about' boots the kernel (so the boot guard runs) with minimal work.
        $cmd = $env . 'php ' . escapeshellarg($projectDir . '/bin/console') . ' about --env=test 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    public function testBootSucceedsWithValidHostConfig(): void
    {
        // No overrides -> .env.test provides ADMIN_DOMAIN/APP_DOMAIN=localhost and .env provides
        // DEFAULT_URI=http://localhost, so the guard passes and the kernel boots.
        [$exitCode] = self::runConsole([]);

        self::assertSame(0, $exitCode, 'Kernel must boot with a valid host configuration.');
    }

    public function testBootFailsWhenAdminDomainIsEmpty(): void
    {
        [$exitCode, $output] = self::runConsole(['ADMIN_DOMAIN' => '']);

        self::assertNotSame(0, $exitCode, 'Kernel must refuse to boot with an empty ADMIN_DOMAIN.');
        self::assertStringContainsString('ADMIN_DOMAIN and APP_DOMAIN must both be set', $output);
    }

    public function testBootFailsWhenAppDomainIsEmpty(): void
    {
        [$exitCode, $output] = self::runConsole(['APP_DOMAIN' => '']);

        self::assertNotSame(0, $exitCode, 'Kernel must refuse to boot with an empty APP_DOMAIN.');
        self::assertStringContainsString('ADMIN_DOMAIN and APP_DOMAIN must both be set', $output);
    }

    public function testBootFailsWhenDefaultUriIsNotAnAbsoluteUrl(): void
    {
        [$exitCode, $output] = self::runConsole(['DEFAULT_URI' => 'not-a-url']);

        self::assertNotSame(0, $exitCode, 'Kernel must refuse to boot when DEFAULT_URI is not an absolute URL.');
        self::assertStringContainsString('DEFAULT_URI must be a valid absolute URL', $output);
    }
}
