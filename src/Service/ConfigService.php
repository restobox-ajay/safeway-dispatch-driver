<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class ConfigService
{
    public function __construct(
        private readonly ConfigRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getString(string $key, string $default = ''): string
    {
        $config = $this->repository->findByKey($key);
        return $config?->getValue() ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $config = $this->repository->findByKey($key);
        if ($config === null) {
            return $default;
        }
        return in_array($config->getValue(), ['1', 'true', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $config = $this->repository->findByKey($key);
        if ($config === null) {
            return $default;
        }
        return (int) $config->getValue();
    }

    public function set(string $key, string $value): void
    {
        $config = $this->repository->findByKey($key);
        if ($config === null) {
            $config = new Config();
            $config->setKey($key);
            $this->em->persist($config);
        }
        $config->setValue($value);
        $this->em->flush();
    }
}
