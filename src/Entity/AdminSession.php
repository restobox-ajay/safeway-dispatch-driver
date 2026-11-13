<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-123: the dedicated session cross-reference record for ADMIN logins — the admin-side mirror of
 * {@see UserSession}, following ADR-003 realm isolation.
 *
 * A SEPARATE table (admin_sessions), keyed on admin_id — NOT a shared user_sessions table with a
 * user_type discriminator (that discriminator was the C31 anti-pattern; it is removed). The same
 * explicit-separation pattern as the admin_login_history / admin_login_notification_seen tables.
 */
#[ORM\Entity(repositoryClass: AdminSessionRepository::class)]
#[ORM\Table(name: 'admin_sessions')]
#[ORM\Index(name: 'IDX_admin_sessions_admin_id', columns: ['admin_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_admin_sessions_session_id', columns: ['session_id'])]
class AdminSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Uniqueness is declared as the named UniqueConstraint above (UNIQ_admin_sessions_session_id) so the
    // mapping matches the migration-created index name; `unique: true` here would make ORM expect a
    // differently-named auto-generated index and break schema:validate (ADR-026).
    #[ORM\Column(name: 'session_id', length: 128)]
    private string $sessionId;

    #[ORM\Column(name: 'admin_id', type: 'integer')]
    private int $adminId;

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

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function setAdminId(int $adminId): static
    {
        $this->adminId = $adminId;
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
