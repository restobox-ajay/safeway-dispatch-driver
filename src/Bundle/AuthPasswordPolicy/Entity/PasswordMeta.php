<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Entity;

use App\Bundle\AuthPasswordPolicy\Repository\PasswordMetaRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * The password-meta satellite (FEATURE-145 / ADR-045): the `password_changed_at` timestamp that used to
 * live as a column on the core `user` table now lives here, in a bundle-owned `password_meta` table with a
 * `user_id` FK. The association is UNIDIRECTIONAL (satellite -> User): core `User` no longer has any
 * password-changed accessor and never references this class, so the whole feature — table and behaviour —
 * is gone when the bundle is not registered.
 *
 * A row exists once a password change has been recorded for the user; it drives password expiry
 * (PasswordExpiryChecker). onDelete CASCADE so deleting a user removes their meta row.
 */
#[ORM\Entity(repositoryClass: PasswordMetaRepository::class)]
#[ORM\Table(name: 'password_meta')]
class PasswordMeta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Unidirectional link to the core user. Unique join column => at most one meta row per user.
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'password_changed_at')]
    private \DateTimeImmutable $passwordChangedAt;

    public function __construct(User $user, \DateTimeImmutable $passwordChangedAt)
    {
        $this->user = $user;
        $this->passwordChangedAt = $passwordChangedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPasswordChangedAt(): \DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function setPasswordChangedAt(\DateTimeImmutable $passwordChangedAt): static
    {
        $this->passwordChangedAt = $passwordChangedAt;
        return $this;
    }
}
