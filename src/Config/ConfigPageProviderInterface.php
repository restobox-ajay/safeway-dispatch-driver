<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('auth.config_page')]
interface ConfigPageProviderInterface
{
    public function getSlug(): string;

    public function getTitle(): string;

    /**
     * Returns field definitions keyed by config key.
     *
     * @return array<string, array{label: string, type: string, default: mixed}>
     */
    public function getFields(): array;
}
