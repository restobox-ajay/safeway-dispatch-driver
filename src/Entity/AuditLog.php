<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditOutcome;
use App\Enum\PrincipalType;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'IDX_AUDIT_LOG_CREATED_AT', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_AUDIT_LOG_ACTION', columns: ['action'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $actor;

    #[ORM\Column(name: 'actor_type', length: 10)]
    private string $actorType;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 20)]
    private string $outcome;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $context = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function setActor(string $actor): static
    {
        $this->actor = $actor;
        return $this;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function setActorType(string $actorType): static
    {
        if (!PrincipalType::isValid($actorType)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid actorType "%s"; allowed: %s',
                $actorType,
                implode(', ', PrincipalType::values()),
            ));
        }

        $this->actorType = $actorType;
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): static
    {
        if (!AuditOutcome::isValid($outcome)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid outcome "%s"; allowed: %s',
                $outcome,
                implode(', ', AuditOutcome::values()),
            ));
        }

        $this->outcome = $outcome;
        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
