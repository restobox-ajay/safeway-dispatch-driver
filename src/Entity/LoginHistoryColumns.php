<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-109 (C8 dumb-data rule): the identical, realm-agnostic columns of a login-history record —
 * the surrogate id, the request context (ip, user_agent, fingerprint) and the created_at stamp.
 *
 * Shared verbatim between the user store ({@see LoginHistory}, keyed on user_id) and the admin store
 * ({@see AdminLoginHistory}, keyed on admin_id). Only the owner-id column is realm-specific and
 * therefore lives on each entity, not here. This trait carries NO behaviour and no owner reference —
 * it is pure dumb data, exactly the sharing the review's C8 rule permits (no abstract base entity, no
 * cross-realm store interface).
 */
trait LoginHistoryColumns
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(name: 'user_agent', length: 512)]
    private string $userAgent;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): static
    {
        $this->ip = $ip;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
