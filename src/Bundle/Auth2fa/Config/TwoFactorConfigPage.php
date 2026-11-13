<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * Moved into auth-2fa-bundle (FEATURE-143 / ADR-043): the /admin/config "Two-Factor Authentication"
 * sub-page (slug `2fa`). Registered via the core `auth.config_page`-tagged
 * {@see ConfigPageProviderInterface} only when the bundle is present, so the sub-page is gone when the
 * bundle is unregistered (closes review C12 for this bundle). The admin realm reads the same
 * `2fa.enforcement.*` keys via ConfigService with defaults, so admin 2FA still functions on defaults
 * when the bundle (and thus this UI page) is absent.
 */
class TwoFactorConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return '2fa';
    }

    public function getTitle(): string
    {
        return 'Two-Factor Authentication';
    }

    public function getFields(): array
    {
        return [
            // Legacy global fallback (FEATURE-126): applied to a principal only when NONE of its
            // roles has a per-role level set below. Kept so deployments that only set this key,
            // and the existing user 2FA behaviour, are unchanged.
            '2fa.enforcement' => [
                'label'   => 'Default 2FA Enforcement (fallback for roles left on "inherit")',
                'type'    => 'enum',
                'options' => ['off', 'optional', 'required'],
                'default' => 'optional',
            ],
            // Per-role enforcement (spec: off/optional/required per role). "inherit" means "use the
            // default above". When a principal holds several configured roles the strictest wins.
            '2fa.enforcement.role.ROLE_USER' => [
                'label'   => '2FA Enforcement — Users (ROLE_USER)',
                'type'    => 'enum',
                'options' => ['inherit', 'off', 'optional', 'required'],
                'default' => 'inherit',
            ],
            '2fa.enforcement.role.ROLE_ADMIN' => [
                'label'   => '2FA Enforcement — Admins (ROLE_ADMIN)',
                'type'    => 'enum',
                'options' => ['inherit', 'off', 'optional', 'required'],
                'default' => 'inherit',
            ],
            '2fa.enforcement.role.ROLE_SUPER_ADMIN' => [
                'label'   => '2FA Enforcement — Superadmins (ROLE_SUPER_ADMIN)',
                'type'    => 'enum',
                'options' => ['inherit', 'off', 'optional', 'required'],
                'default' => 'inherit',
            ],
            'trusted_device.lifetime_days' => [
                'label'   => 'Trusted Device Lifetime (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
            '2fa.trusted_ips' => [
                'label'   => '2FA Trusted IPs (comma-separated, CIDR supported)',
                'type'    => 'text',
                'default' => '',
            ],
        ];
    }
}
