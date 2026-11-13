<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class ConfigPageRegistry
{
    public function __construct(
        #[AutowireIterator('auth.config_page')] private readonly iterable $providers,
    ) {}

    /** @return list<ConfigPageProviderInterface> */
    public function getAll(): array
    {
        return iterator_to_array($this->providers, false);
    }

    public function getBySlug(string $slug): ?ConfigPageProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getSlug() === $slug) {
                return $provider;
            }
        }
        return null;
    }
}
