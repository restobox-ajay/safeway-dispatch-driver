<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-103 (AXIS-2 / review C8): the identical, realm-agnostic fields and lifecycle of a
 * bearer/personal access token — the surrogate id, the human name, the token hash, the
 * expiry/last-used/revoked/created stamps, and the expired/revoked/active predicates plus revoke().
 *
 * Shared verbatim between the user token ({@see PersonalAccessToken}, table personal_access_tokens,
 * owner user_id) and the admin API token ({@see AdminAccessToken}, table admin_access_tokens, owner
 * admin_id). The two remain DISTINCT entities/tables on separate security boundaries (ADR-003): the
 * admin_api firewall authenticates Admin entities via AdminAccessToken, so a User can never reach the
 * admin API. Only the owner-id column + its getter + its index + the constructor (which assigns the
 * realm-specific owner id) live on each entity — this trait carries NO owner reference. Pure dumb
 * data, exactly the sharing C8 permits (no abstract base entity, no cross-realm interface).
 */
trait AccessTokenColumns
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(\DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }
}
