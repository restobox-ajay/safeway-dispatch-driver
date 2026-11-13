<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Entity;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * The USER 2FA satellite (FEATURE-143 / ADR-043): the TOTP enrolment state that used to live as
 * columns on the core `user` table now lives here, in a bundle-owned `two_factor_settings` table with
 * a `user_id` FK. The association is UNIDIRECTIONAL (satellite -> User): core `User` no longer has any
 * TOTP accessor and never references this class, so the whole feature — table and behaviour — is gone
 * when the bundle is not registered.
 */
#[ORM\Entity(repositoryClass: TwoFactorSettingsRepository::class)]
#[ORM\Table(name: 'two_factor_settings')]
class TwoFactorSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Unidirectional link to the core user. Unique join column => at most one settings row per user.
    // onDelete CASCADE so deleting a user removes their 2FA state (mirrors the old column ownership).
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTotpEnabled = false;

    // Highest TOTP time-counter already accepted; codes at or below it are rejected (replay protection).
    #[ORM\Column(nullable: true)]
    private ?int $lastTotpCounter = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $secret): static
    {
        $this->totpSecret = $secret;
        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->isTotpEnabled;
    }

    public function setIsTotpEnabled(bool $enabled): static
    {
        $this->isTotpEnabled = $enabled;
        return $this;
    }

    public function getLastTotpCounter(): ?int
    {
        return $this->lastTotpCounter;
    }

    public function setLastTotpCounter(?int $counter): static
    {
        $this->lastTotpCounter = $counter;
        return $this;
    }
}
