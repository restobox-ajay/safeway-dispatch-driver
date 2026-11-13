<?php

declare(strict_types=1);

namespace App\Driver\Api;

/**
 * A signed-in driver's Safeway Driver API tokens and the driver object, kept server-side in the PHP session
 * (never sent to the browser). There is no database: the session file is the only place they live.
 */
final class DriverTokens
{
    /** @param array<string, mixed> $driver */
    public function __construct(
        private string $accessToken,
        private string $refreshToken,
        private int $accessExpiresAt,
        private array $driver,
        private readonly string $deviceId,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): ?self
    {
        if (!\is_string($a['access'] ?? null) || !\is_string($a['refresh'] ?? null) || !\is_int($a['expires'] ?? null) || !\is_array($a['driver'] ?? null)) {
            return null;
        }

        return new self($a['access'], $a['refresh'], $a['expires'], $a['driver'], \is_string($a['device'] ?? null) ? $a['device'] : 'web-unknown');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['access' => $this->accessToken, 'refresh' => $this->refreshToken, 'expires' => $this->accessExpiresAt, 'driver' => $this->driver, 'device' => $this->deviceId];
    }

    public function withTokens(string $accessToken, string $refreshToken, int $expiresIn, int $now): self
    {
        $copy = clone $this;
        $copy->accessToken = $accessToken;
        $copy->refreshToken = $refreshToken;
        $copy->accessExpiresAt = $now + max(1, $expiresIn);

        return $copy;
    }

    /** @param array<string, mixed> $driver */
    public function withDriver(array $driver): self
    {
        $copy = clone $this;
        $copy->driver = $driver + $this->driver;

        return $copy;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getAccessExpiresAt(): int
    {
        return $this->accessExpiresAt;
    }

    /** @return array<string, mixed> */
    public function getDriver(): array
    {
        return $this->driver;
    }

    public function getDriverId(): int
    {
        return (int) ($this->driver['id'] ?? 0);
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }
}
