<?php

declare(strict_types=1);

namespace App\Security\Driver;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A driver signed in to the /driver firewall (ADR-074). Not a boilerplate user and not stored here: the
 * driver lives in fvstexpress-admin, and this is built from the admin API's driver object.
 */
final class DriverUser implements UserInterface
{
    public const ROLE = 'ROLE_DRIVER';

    /** @param array<string, mixed> $driver */
    public function __construct(private readonly array $driver) {}

    public static function identifierFor(int $driverId): string
    {
        return 'driver:' . $driverId;
    }

    public function getUserIdentifier(): string
    {
        return self::identifierFor($this->getId());
    }

    public function getId(): int
    {
        return (int) ($this->driver['id'] ?? 0);
    }

    public function getName(): string
    {
        return (string) ($this->driver['name'] ?? '');
    }

    /** @return array<string, mixed> the admin API's driver object (/me) */
    public function getDriver(): array
    {
        return $this->driver;
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }
}
