<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-103 (AXIS-2 / review C8, C32): the identical, realm-agnostic fields and lifecycle of a
 * single-use password-reset token — the surrogate id, the target email, the token hash, the
 * expiry/used/created stamps, and the used/expired/valid predicates.
 *
 * Shared verbatim between the user token ({@see PasswordResetToken}, table password_reset_tokens)
 * and the admin token ({@see AdminPasswordResetToken}, table admin_password_reset_tokens). The two
 * remain DISTINCT entities and DISTINCT tables (ADR-003 realm isolation / ADR-009: a user and an
 * admin sharing an email must never consume each other's tokens) — only the class-level ORM attrs
 * (Entity+repository, Table, per-realm email Index) live on each entity.
 *
 * Collapsing these into one trait also resolves the C32 drift: the admin token previously carried
 * declare(strict_types=1) but LACKED getCreatedAt(), while the user token had getCreatedAt() but
 * LACKED strict_types. Both are now strict and both expose getCreatedAt(). This trait carries no
 * owner reference and no realm-specific behaviour — pure dumb data, exactly the sharing C8 permits.
 */
trait PasswordResetTokenColumns
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}
