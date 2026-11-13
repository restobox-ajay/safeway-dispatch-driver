<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Entity;

use App\Bundle\AuthSecurity\Repository\AccountLockoutRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * The account-lockout satellite (FEATURE-144 / ADR-044): the temporary hard-lockout state that used to
 * live as a `locked_until` column on the core `user` table now lives here, in a bundle-owned
 * `account_lockouts` table with a `user_id` FK. The association is UNIDIRECTIONAL (satellite -> User):
 * core `User` no longer has any lockout accessor and never references this class, so the whole feature —
 * table and behaviour — is gone when the bundle is not registered.
 *
 * A row exists only while an account is locked; an admin unlock DELETEs it. An expired row is harmless
 * (the manager only reports a lockout while `locked_until` is still in the future).
 */
#[ORM\Entity(repositoryClass: AccountLockoutRepository::class)]
#[ORM\Table(name: 'account_lockouts')]
class AccountLockout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Unidirectional link to the core user. Unique join column => at most one lockout row per user.
    // onDelete CASCADE so deleting a user removes their lockout (mirrors the old column ownership).
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'locked_until')]
    private \DateTimeImmutable $lockedUntil;

    public function __construct(User $user, \DateTimeImmutable $lockedUntil)
    {
        $this->user = $user;
        $this->lockedUntil = $lockedUntil;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLockedUntil(): \DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function setLockedUntil(\DateTimeImmutable $lockedUntil): static
    {
        $this->lockedUntil = $lockedUntil;
        return $this;
    }
}
