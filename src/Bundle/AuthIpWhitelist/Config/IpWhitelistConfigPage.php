<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * The IP-whitelist /admin/config sub-page (FEATURE-146 / ADR-046), moved into auth-ip-whitelist-bundle so
 * that with the bundle unregistered the sub-page is gone (nothing tags auth.config_page for it). It edits the
 * GLOBAL whitelist rows in the core `config` store; the core AdminConfigController serves it via
 * ConfigPageRegistry (autoconfigured from the ConfigPageProviderInterface attribute).
 */
class IpWhitelistConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'ip-whitelist';
    }

    public function getTitle(): string
    {
        return 'IP Whitelist';
    }

    public function getFields(): array
    {
        return [
            'ip_whitelist.user_ips' => [
                'label'   => 'User Login Allowed IPs (comma-separated, CIDR supported; empty = allow all)',
                'type'    => 'ip_list',
                'default' => '',
            ],
            'ip_whitelist.admin_ips' => [
                'label'   => 'Admin Login Allowed IPs (comma-separated, CIDR supported; empty = allow all)',
                'type'    => 'ip_list',
                'default' => '',
                // Refuse a list that would not admit the admin saving it (issue #17); recovery from the
                // shell: app:ip-whitelist:clear.
                'require_client_ip' => true,
            ],
        ];
    }
}
