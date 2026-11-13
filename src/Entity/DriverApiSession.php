<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DriverApiSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A signed-in driver's tokens for the fvstexpress-admin driver API (ADR-074).
 *
 * The driver's PHP session holds only a random key; this row holds its SHA-256 hash, so the session
 * table alone gives nothing. The admin tokens themselves must be stored as-is (they are sent on every
 * call). They live in a row rather than in the session because the refresh token rotates on every
 * refresh: two requests refreshing at once would sign the driver out. DriverTokenStore locks this row
 * while refreshing, and the loser of the race reuses the winner's new token.
 *
 * `driverId` and `driverJson` are the admin API's /me answer (plain values, not relations: the driver
 * lives in the admin app).
 */
#[ORM\Entity(repositoryClass: DriverApiSessionRepository::class)]
#[ORM\Table(name: 'driver_api_session')]
#[ORM\UniqueConstraint(name: 'uniq_driver_api_session_key', fields: ['keyHash'])]
class DriverApiSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SHA-256 of the random key in the driver's PHP session. */
    #[ORM\Column(length: 64)]
    private string $keyHash;

    #[ORM\Column]
    private int $driverId;

    /** The admin API's driver object (from login and /me), as JSON. */
    #[ORM\Column(type: 'text')]
    private string $driverJson;

    #[ORM\Column(type: 'text')]
    private string $accessToken;

    #[ORM\Column(type: 'text')]
    private string $refreshToken;

    /** Refresh a little before this, so a call rarely meets an expired token. */
    #[ORM\Column]
    private \DateTimeImmutable $accessExpiresAt;

    /** Shown in the admin's Driver API Log; one per browser (cookie). */
    #[ORM\Column(length: 64)]
    private string $deviceId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    /** @param array<string, mixed> $driver */
    public function __construct(string $keyHash, array $driver, string $deviceId)
    {
        $this->keyHash = $keyHash;
        $this->deviceId = $deviceId;
        $this->setDriver($driver);
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getDriverId(): int
    {
        return $this->driverId;
    }

    /** @return array<string, mixed> */
    public function getDriver(): array
    {
        $driver = json_decode($this->driverJson, true);

        return \is_array($driver) ? $driver : [];
    }

    /** @param array<string, mixed> $driver */
    public function setDriver(array $driver): static
    {
        $this->driverId = (int) ($driver['id'] ?? 0);
        $this->driverJson = json_encode($driver, \JSON_THROW_ON_ERROR);

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getAccessExpiresAt(): \DateTimeImmutable
    {
        return $this->accessExpiresAt;
    }

    public function setTokens(string $accessToken, string $refreshToken, int $expiresIn, \DateTimeImmutable $now): static
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->accessExpiresAt = $now->modify(sprintf('+%d seconds', max(0, $expiresIn)));

        return $this;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->lastUsedAt = $now;

        return $this;
    }
}
