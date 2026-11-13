<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Entity;

use App\Bundle\AuthPasswordPolicy\Repository\PasswordHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recent-password-hash history for reuse prevention. Moved into auth-password-policy-bundle
 * (FEATURE-145 / ADR-045): the table `password_history` is unchanged; only the mapping's namespace moved,
 * so the whole reuse-prevention feature (table + logic) is bundle-owned and gone when the bundle is absent.
 * Keyed on a scalar user_id (no FK, matching the historical shape) so it is unaffected by which realm owns
 * the user row.
 */
#[ORM\Entity(repositoryClass: PasswordHistoryRepository::class)]
#[ORM\Table(name: 'password_history')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_ph_user_id_created')]
class PasswordHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, string $passwordHash)
    {
        $this->userId = $userId;
        $this->passwordHash = $passwordHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
