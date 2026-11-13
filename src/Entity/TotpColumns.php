<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The TOTP (two-factor) column set for the {@see Admin} realm.
 *
 * This is dumb data only — the field mappings and accessors for admin TOTP enrolment and replay
 * protection. Historically shared with {@see User}, but as of FEATURE-143 / ADR-043 the USER 2FA
 * state moved off `user` into the auth-2fa-bundle satellite table (two_factor_settings), so this trait
 * is now used ONLY by {@see Admin}. The admin realm's own 2FA (AdminTwoFactorController etc.) stays in
 * core and is not part of the extracted user bundle.
 */
trait TotpColumns
{
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTotpEnabled = false;

    // Highest TOTP time-counter already accepted; codes at or below it are rejected (replay protection).
    #[ORM\Column(nullable: true)]
    private ?int $lastTotpCounter = null;

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
