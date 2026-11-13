<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountStatus;
use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: 'admin')]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // TOTP two-factor columns (totp_secret / is_totp_enabled / last_totp_counter), shared
    // verbatim with User via the dumb-data column trait (FEATURE-126). The Admin realm stays a
    // fully distinct entity type; only the column mapping crosses the boundary (ADR-003).
    use TotpColumns;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * The complete set of roles an Admin account may hold — the single source of truth.
     *
     * ROLE_ADMIN is the baseline (always present via getRoles()). ROLE_SUPER_ADMIN is the
     * client-facing elevation (granted via the app:create-superadmin command).
     * ROLE_TECH_SUPPORT is the maintainer tier (app:create-tech-support): superadmin powers
     * via role_hierarchy, but hidden from every admin-management surface for non-tech-support
     * viewers (ADR-050) so maintainer accounts never show on a client's staff list. Downstream
     * apps add their own admin-tier roles here. User-tier roles have no meaning on an Admin
     * and are dropped; see User::ALLOWED_ROLES for the mirror image on the user side.
     */
    public const ALLOWED_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT'];

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_ADMIN';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        // Allowlist (default-deny): anything not in ALLOWED_ROLES is dropped on write.
        $this->roles = array_values(array_intersect($roles, self::ALLOWED_ROLES));
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        // Default-deny, mirroring setRoles(): only AccountStatus values are persistable.
        if (!AccountStatus::isValid($status)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid status "%s"; allowed: %s',
                $status,
                implode(', ', AccountStatus::values()),
            ));
        }

        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
        // no plaintext credentials stored on this entity
    }

    /**
     * Invalidate the security token when the password, status, or roles change underneath a
     * live session (mirrors User): a deactivated, password-reset, or demoted admin is logged
     * out on their next request rather than lingering with a stale token.
     *
     * Roles must be compared here explicitly: because Admin implements EquatableInterface,
     * ContextListener::hasUserChanged relies solely on isEqualTo and skips its own role-name
     * comparison, so omitting roles would let a demoted superadmin keep ROLE_SUPER_ADMIN in a
     * live session until they re-authenticate (review C23 / FEATURE-133).
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        $theseRoles = $this->getRoles();
        $otherRoles = $user->getRoles();
        sort($theseRoles);
        sort($otherRoles);

        return $user->getPassword() === $this->password
            && $user->getStatus() === $this->status
            && $theseRoles === $otherRoles;
    }
}
