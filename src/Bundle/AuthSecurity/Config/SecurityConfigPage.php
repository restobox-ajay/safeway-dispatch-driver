<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * The "Security" /admin/config sub-page (login rate limit + account lockout knobs). Moved into
 * auth-security-bundle (FEATURE-144 / ADR-044): it is autoconfigured with the `auth.config_page` tag only
 * when the bundle is registered, so with the bundle absent the sub-page is gone (closes review C12 for
 * this bundle) — consistent with the rate-limit/lockout behaviour it configures also being gone.
 */
class SecurityConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'security';
    }

    public function getTitle(): string
    {
        return 'Security';
    }

    public function getFields(): array
    {
        return [
            'rate_limit.max_attempts' => [
                'label'   => 'Max Auth Attempts per Window (0 = disabled). Governs login, forgot-password, magic-link, resend-verification, and 2FA challenge.',
                'type'    => 'int',
                'min'     => 0,
                'default' => '10',
            ],
            'rate_limit.window_seconds' => [
                'label'   => 'Rate Limit Window (seconds)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '300',
            ],
            'lockout.max_attempts' => [
                'label'   => 'Account Lockout Max Attempts (0 = disabled)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
            'lockout.duration_minutes' => [
                'label'   => 'Account Lockout Duration (minutes)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '15',
            ],
        ];
    }
}
