<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Bearer token for the admin REST API (/admin-api/*). Belongs to an Admin entity — NOT a
 * User (owner column admin_id). This is what keeps the admin API on the admin security boundary:
 * the admin_api firewall authenticates Admin entities via these tokens, so a normal User can never
 * reach the admin API regardless of any role it carries (ADR-003).
 *
 * The identical fields/lifecycle are shared with the user token via {@see AccessTokenColumns}; only
 * the owner-id column, its getter, its index, and the constructor are realm-specific.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AdminAccessTokenRepository::class)]
#[ORM\Table(name: 'admin_access_tokens')]
#[ORM\Index(name: 'IDX_AAT_ADMIN_ID', columns: ['admin_id'])]
class AdminAccessToken
{
    use AccessTokenColumns;

    #[ORM\Column]
    private int $adminId;

    /**
     * The last {@see HINT_LENGTH} characters of the plaintext, kept so a person can tell which token is which
     * when revoking (issue #39) — only the SHA-256 hash is stored, so the plaintext itself is unrecoverable.
     * 6 hex chars of a 64-char random token reveal nothing useful. Null for tokens issued before hints existed.
     */
    #[ORM\Column(length: 6, nullable: true)]
    private ?string $tokenHint = null;

    public const HINT_LENGTH = 6;

    public function __construct(int $adminId, string $name, string $tokenHash, ?\DateTimeImmutable $expiresAt = null, ?string $tokenHint = null)
    {
        $this->adminId = $adminId;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->tokenHint = $tokenHint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getTokenHint(): ?string
    {
        return $this->tokenHint;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }
}
