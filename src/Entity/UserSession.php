<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSessionRepository::class)]
#[ORM\Table(name: 'user_sessions')]
#[ORM\Index(name: 'IDX_user_sessions_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_user_sessions_session_id', columns: ['session_id'])]
class UserSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Uniqueness is declared as the named UniqueConstraint above (UNIQ_user_sessions_session_id)
    // so the mapping matches the migration-created index name; `unique: true` here would make
    // ORM expect a differently-named auto-generated index and break schema:validate.
    #[ORM\Column(name: 'session_id', length: 128)]
    private string $sessionId;

    #[ORM\Column(name: 'user_id', type: 'integer')]
    private int $userId;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(name: 'user_agent', length: 512)]
    private string $userAgent;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_active_at')]
    private \DateTimeImmutable $lastActiveAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastActiveAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastActiveAt(): \DateTimeImmutable
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(\DateTimeImmutable $lastActiveAt): static
    {
        $this->lastActiveAt = $lastActiveAt;
        return $this;
    }
}
