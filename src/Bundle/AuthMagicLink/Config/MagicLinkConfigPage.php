<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * Admin /config sub-page for the magic-link feature — owned by auth-magic-link-bundle (FEATURE-140).
 * It implements the core {@see ConfigPageProviderInterface} (which carries the `auth.config_page`
 * autoconfigure tag), so with the bundle's autoconfigured service loading it is collected by
 * ConfigPageRegistry ONLY when the bundle is registered; with the bundle absent the sub-page is gone
 * (closes review C12 for this bundle). Slug 'magic-link' is unchanged from the pre-extraction page.
 */
class MagicLinkConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'magic-link';
    }

    public function getTitle(): string
    {
        return 'Magic Link';
    }

    public function getFields(): array
    {
        return [
            'magic_link.expiry_minutes' => [
                'label'   => 'Magic Link Expiry (minutes)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '15',
            ],
        ];
    }
}
