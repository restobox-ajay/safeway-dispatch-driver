<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TwoFactorEnforcementResolver;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-126 (review C37): per-role 2FA enforcement replaces the single global flag. The
 * resolver reads a per-role level for each role a principal holds, takes the strictest, and
 * falls back to the legacy global key when no role is configured.
 */
final class TwoFactorEnforcementResolverTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function resolver(array $config): TwoFactorEnforcementResolver
    {
        $configService = new class($config) extends ConfigService {
            /** @param array<string, string> $map */
            public function __construct(private array $map) {}

            public function getString(string $key, string $default = ''): string
            {
                return $this->map[$key] ?? $default;
            }
        };

        return new TwoFactorEnforcementResolver($configService);
    }

    public function testFallsBackToGlobalWhenNoRoleConfigured(): void
    {
        $resolver = $this->resolver(['2fa.enforcement' => 'required']);

        self::assertSame('required', $resolver->resolveForRoles(['ROLE_USER']));
    }

    public function testDefaultsToOptionalWhenNothingConfigured(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame('optional', $resolver->resolveForRoles(['ROLE_USER']));
    }

    public function testInheritIsTreatedAsUnconfigured(): void
    {
        $resolver = $this->resolver([
            '2fa.enforcement.role.ROLE_USER' => 'inherit',
            '2fa.enforcement'                => 'off',
        ]);

        self::assertSame('off', $resolver->resolveForRoles(['ROLE_USER']));
    }

    public function testPerRoleLevelBeatsGlobalFallback(): void
    {
        $resolver = $this->resolver([
            '2fa.enforcement.role.ROLE_ADMIN' => 'required',
            '2fa.enforcement'                 => 'off',
        ]);

        self::assertSame('required', $resolver->resolveForRoles(['ROLE_ADMIN']));
    }

    public function testStrictestConfiguredRoleWins(): void
    {
        // A superadmin holds both ROLE_SUPER_ADMIN and ROLE_ADMIN; the strictest configured
        // level (required) must win over the laxer one (off).
        $resolver = $this->resolver([
            '2fa.enforcement.role.ROLE_ADMIN'       => 'off',
            '2fa.enforcement.role.ROLE_SUPER_ADMIN' => 'required',
        ]);

        self::assertSame('required', $resolver->resolveForRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']));
        // Order-independent.
        self::assertSame('required', $resolver->resolveForRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
    }

    public function testLegacyGlobalKeyIsUserScopedAndDoesNotForceAdmins(): void
    {
        // The legacy global key historically governed the USER realm only (admins had no 2FA).
        // Setting it must NOT retroactively force an admin who has no per-role admin key.
        $resolver = $this->resolver(['2fa.enforcement' => 'required']);

        self::assertSame('required', $resolver->resolveForRoles(['ROLE_USER']));
        self::assertSame('optional', $resolver->resolveForRoles(['ROLE_ADMIN']));
        self::assertSame('optional', $resolver->resolveForRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']));
    }

    public function testPlainAdminNotForcedWhenOnlyItsRoleIsOff(): void
    {
        $resolver = $this->resolver([
            '2fa.enforcement.role.ROLE_ADMIN'       => 'off',
            '2fa.enforcement.role.ROLE_SUPER_ADMIN' => 'required',
        ]);

        self::assertSame('off', $resolver->resolveForRoles(['ROLE_ADMIN']));
    }

    public function testTechSupportAlwaysRequiredRegardlessOfConfig(): void
    {
        // ROLE_TECH_SUPPORT is a mandatory-2FA role (ADR-050): required is enforced in code and
        // cannot be relaxed even if a config key tried to set it off.
        $resolver = $this->resolver([
            '2fa.enforcement.role.ROLE_TECH_SUPPORT' => 'off',
            '2fa.enforcement.role.ROLE_SUPER_ADMIN'  => 'off',
            '2fa.enforcement.role.ROLE_ADMIN'        => 'off',
            '2fa.enforcement'                        => 'off',
        ]);

        // A tech-support admin stores ['ROLE_TECH_SUPPORT'] and gets ROLE_SUPER_ADMIN/ROLE_ADMIN
        // appended by the role hierarchy / getRoles(); required must hold across all forms.
        self::assertSame('required', $resolver->resolveForRoles(['ROLE_TECH_SUPPORT']));
        self::assertSame('required', $resolver->resolveForRoles(['ROLE_TECH_SUPPORT', 'ROLE_SUPER_ADMIN', 'ROLE_ADMIN']));
    }

    public function testTechSupportRequiredEvenWithNoConfigAtAll(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame('required', $resolver->resolveForRoles(['ROLE_TECH_SUPPORT']));
    }
}
