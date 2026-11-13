<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Entity;

use App\Entity\AccessTokenColumns;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personal access token for the user API. Belongs to a User (owner column user_id). The identical
 * fields/lifecycle are shared with the admin API token via {@see AccessTokenColumns} (which stays in
 * core because AdminAccessToken also uses it); only the owner-id column, its getter, its index, and
 * the constructor are realm-specific (ADR-003). Owned by auth-pat-bundle (FEATURE-138).
 */
#[ORM\Entity(repositoryClass: \App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository::class)]
#[ORM\Table(name: 'personal_access_tokens')]
#[ORM\Index(name: 'IDX_PAT_USER_ID', columns: ['user_id'])]
class PersonalAccessToken
{
    use AccessTokenColumns;

    #[ORM\Column]
    private int $userId;

    public function __construct(int $userId, string $name, string $tokenHash, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->userId = $userId;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
