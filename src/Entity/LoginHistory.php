<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginHistoryRepository::class)]
#[ORM\Table(name: 'login_history')]
#[ORM\Index(name: 'idx_login_history_user_id', columns: ['user_id'])]
class LoginHistory
{
    // The realm-agnostic columns (id, ip, user_agent, fingerprint, created_at) are shared verbatim
    // with the admin store via this trait (FEATURE-109, C8 dumb-data rule). Only user_id is local.
    use LoginHistoryColumns;

    #[ORM\Column(name: 'user_id', type: 'integer')]
    private int $userId;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }
}
