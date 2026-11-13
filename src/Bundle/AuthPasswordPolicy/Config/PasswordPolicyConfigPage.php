<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * The "Password Policy" /admin/config sub-page (min length, character-class rules, expiry, reuse count).
 * Moved into auth-password-policy-bundle (FEATURE-145 / ADR-045): it is autoconfigured with the
 * `auth.config_page` tag only when the bundle is registered, so with the bundle absent the sub-page is gone
 * (closes review C12 for this bundle) — consistent with the policy/expiry/reuse behaviour it configures
 * also being gone.
 */
class PasswordPolicyConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'password-policy';
    }

    public function getTitle(): string
    {
        return 'Password Policy';
    }

    public function getFields(): array
    {
        return [
            'password_policy.min_length' => [
                'label'   => 'Minimum Password Length (0 = disabled)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
            'password_policy.require_uppercase' => [
                'label'   => 'Require Uppercase Letter',
                'type'    => 'bool',
                'default' => '0',
            ],
            'password_policy.require_number' => [
                'label'   => 'Require Number',
                'type'    => 'bool',
                'default' => '0',
            ],
            'password_policy.require_symbol' => [
                'label'   => 'Require Symbol',
                'type'    => 'bool',
                'default' => '0',
            ],
            'password_policy.expiry_days' => [
                'label'   => 'Password Expiry (days, 0 = disabled)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
            'password_policy.reuse_count' => [
                'label'   => 'Prevent Reuse of Last N Passwords (0 = disabled)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
        ];
    }
}
