<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\Entity;

use App\Bundle\AuthIpWhitelist\Repository\UserIpWhitelistRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * The IP-whitelist satellite (FEATURE-146 / ADR-046): the per-user `allowed_ips` override that used to live
 * as a column on the core `user` table now lives here, in a bundle-owned `user_ip_whitelist` table with a
 * `user_id` FK. The association is UNIDIRECTIONAL (satellite -> User): core `User` no longer has any
 * allowed-IPs accessor and never references this class, so the whole feature — table and behaviour — is gone
 * when the bundle is not registered.
 *
 * A row exists ONLY when the user has a per-user override; clearing the override deletes the row. onDelete
 * CASCADE so deleting a user removes their whitelist row.
 */
#[ORM\Entity(repositoryClass: UserIpWhitelistRepository::class)]
#[ORM\Table(name: 'user_ip_whitelist')]
class UserIpWhitelist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Unidirectional link to the core user. Unique join column => at most one whitelist row per user.
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'allowed_ips', type: 'text')]
    private string $allowedIps;

    public function __construct(User $user, string $allowedIps)
    {
        $this->user = $user;
        $this->allowedIps = $allowedIps;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAllowedIps(): string
    {
        return $this->allowedIps;
    }

    public function setAllowedIps(string $allowedIps): static
    {
        $this->allowedIps = $allowedIps;
        return $this;
    }
}
