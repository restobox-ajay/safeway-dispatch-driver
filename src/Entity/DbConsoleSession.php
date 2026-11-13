<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DbConsoleSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One open phpLiteAdmin console session (ADR-053).
 *
 * The row IS the credential's server side: the client holds an opaque random token, this holds only
 * its SHA-256 hash, so a database leak yields nothing usable. Having a row (rather than a
 * self-contained signed cookie) is what makes the credential revocable and expirable — deleting the
 * row ends the session on its next request.
 *
 * `adminId` is a plain scalar, not a relation, matching how every other satellite table in this
 * project keys its owner (ADR-006/ADR-034): the gateway reads this table with raw PDO outside the
 * kernel, so a Doctrine association would buy nothing it can use.
 */
#[ORM\Entity(repositoryClass: DbConsoleSessionRepository::class)]
#[ORM\Table(name: 'db_console_session')]
#[ORM\UniqueConstraint(name: 'uniq_db_console_session_token', fields: ['tokenHash'])]
#[ORM\Index(name: 'idx_db_console_session_admin', fields: ['adminId'])]
class DbConsoleSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SHA-256 of the token handed to the client. The raw token is never stored. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private int $adminId;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** The console is bound to the IP it was opened from, so a stolen token cannot travel. */
    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;

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

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
