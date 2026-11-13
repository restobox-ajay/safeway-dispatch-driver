<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * Moved into auth-pat-bundle (review C12 follow-up): the /admin/config "Personal Access Tokens"
 * sub-page (slug `pat`). Registered via the core `auth.config_page`-tagged
 * {@see ConfigPageProviderInterface} only when the bundle is present (the bundle's services.php glob
 * loads Config/ and autoconfigure applies the tag), so the sub-page is gone when the bundle is
 * unregistered — asserted by AuthPatBundleModularityTest. PAT was the first extraction (FEATURE-138)
 * and predated the config-page-into-bundle pattern the other seven bundles followed; this closes the
 * gap so all nine ConfigPages live where they belong (GeneralConfigPage stays in core).
 */
class PATConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'pat';
    }

    public function getTitle(): string
    {
        return 'Personal Access Tokens';
    }

    public function getFields(): array
    {
        return [
            'pat.default_expiry_days' => [
                'label'   => 'Default Token Expiry (days, 0 = never)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
            'pat.max_tokens_per_user' => [
                'label'   => 'Max Tokens Per User (0 = unlimited)',
                'type'    => 'int',
                'min'     => 0,
                'default' => '0',
            ],
        ];
    }
}
