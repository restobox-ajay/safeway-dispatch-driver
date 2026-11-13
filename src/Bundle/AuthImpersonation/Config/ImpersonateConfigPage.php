<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * The Impersonation admin /config sub-page, owned by auth-impersonate-bundle (FEATURE-142 / ADR-041).
 * Registered via ConfigPageProviderInterface (auto-tagged `auth.config_page`) ONLY when the bundle's
 * autoconfigured services load — so with the bundle absent the sub-page is gone (AC3, closes review
 * C12 for this bundle). Slug/fields unchanged from the pre-extraction core class.
 */
class ImpersonateConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'impersonate';
    }

    public function getTitle(): string
    {
        return 'Impersonation';
    }

    public function getFields(): array
    {
        return [
            'impersonate.enabled' => [
                'label'   => 'Allow Impersonation',
                'type'    => 'bool',
                'default' => '1',
            ],
        ];
    }
}
