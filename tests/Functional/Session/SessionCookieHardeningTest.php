<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FEATURE-089: Session cookie hardening (security review M4).
 *
 * The session cookie flags must be pinned explicitly in framework.yaml rather
 * than relying on framework defaults. These tests assert the *compiled*
 * configuration (the `session.storage.options` container parameter), so they
 * fail if the pin is removed and the values drift back to framework defaults.
 */
final class SessionCookieHardeningTest extends KernelTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function sessionStorageOptions(): array
    {
        self::bootKernel();

        return self::getContainer()->getParameter('session.storage.options');
    }

    public function testCookieSecureIsAuto(): void
    {
        $options = $this->sessionStorageOptions();

        // 'auto' => Secure flag emitted on HTTPS requests only. The stack also
        // serves http://auth.localhost, so a hard `true` would break it.
        self::assertSame('auto', $options['cookie_secure'] ?? null);
    }

    public function testCookieHttpOnlyIsTrue(): void
    {
        $options = $this->sessionStorageOptions();

        self::assertTrue($options['cookie_httponly'] ?? null);
    }

    public function testCookieSameSiteIsLax(): void
    {
        $options = $this->sessionStorageOptions();

        self::assertSame('lax', $options['cookie_samesite'] ?? null);
    }

    public function testFlagsArePinnedExplicitlyInFrameworkYaml(): void
    {
        $frameworkYaml = file_get_contents(
            dirname(__DIR__, 3) . '/config/packages/framework.yaml'
        );

        self::assertStringContainsString(
            'cookie_secure: auto',
            $frameworkYaml,
            'framework.yaml must pin cookie_secure explicitly'
        );
        self::assertStringContainsString(
            'cookie_httponly: true',
            $frameworkYaml,
            'framework.yaml must pin cookie_httponly explicitly'
        );
        self::assertStringContainsString(
            'cookie_samesite: lax',
            $frameworkYaml,
            'framework.yaml must pin cookie_samesite explicitly'
        );
    }
}
