<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
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

    #[ORM\Column(length: 20)]
    private string $status = 'active';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $loginNotificationsEnabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    // The user's TOTP 2FA state no longer lives here: it moved off `user` into the auth-2fa-bundle
    // satellite table two_factor_settings (unidirectional TwoFactorSettings -> User). Core `User` has
    // no TOTP accessor and never references the bundle (FEATURE-143 / ADR-043).

    // The account-lockout state no longer lives here: it moved off `user` into the auth-security-bundle
    // satellite table account_lockouts (unidirectional AccountLockout -> User). Core `User` has no lockout
    // accessor and never references the bundle; lockout is read/cleared through the
    // App\Security\AccountLockManagerInterface port (FEATURE-144 / ADR-044).

    // The password-changed timestamp no longer lives here: it moved off `user` into the
    // auth-password-policy-bundle satellite table password_meta (unidirectional PasswordMeta -> User).
    // Core `User` has no password-changed accessor and never references the bundle; the change time is
    // read/recorded through the App\Security\PasswordPolicyManagerInterface port (FEATURE-145 / ADR-045).

    // Marker bumped on "logout everywhere" / force-logout / deactivation. Folded into the
    // remember-me HMAC so bumping it invalidates every outstanding remember-me cookie for this
    // user at once, without touching the password.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sessionsInvalidatedAt = null;

    // The per-user IP-whitelist override no longer lives here: it moved off `user` into the
    // auth-ip-whitelist-bundle satellite table user_ip_whitelist (unidirectional UserIpWhitelist -> User).
    // Core `User` has no allowed-IPs accessor and never references the bundle; the override is read/written
    // through the App\Security\IpWhitelistManagerInterface port (FEATURE-146 / ADR-046).

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
     * The complete set of roles a User account may hold — the single source of truth.
     *
     * ROLE_USER is the baseline (always present via getRoles()). Downstream applications
     * add their own non-admin, user-tier roles here (e.g. 'ROLE_EDITOR', 'ROLE_BILLING').
     *
     * Admin roles (ROLE_ADMIN, ROLE_SUPER_ADMIN) MUST NOT be listed: admins are a fully
     * separate entity and firewall, and a User carrying an admin role would collapse that
     * security boundary. setRoles() enforces this allowlist, so no caller — controller,
     * API, console command, or future code — can ever escalate a User into admin space.
     */
    public const ALLOWED_ROLES = ['ROLE_USER'];

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
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
        // Default-deny, mirroring setRoles(): only AccountStatus values are persistable,
        // so no write path (controller, API, console, or future code) can ever store an
        // out-of-set status like 'banned'. Status is load-bearing (login gating, isEqualTo).
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isLoginNotificationsEnabled(): bool
    {
        return $this->loginNotificationsEnabled;
    }

    public function setLoginNotificationsEnabled(bool $enabled): static
    {
        $this->loginNotificationsEnabled = $enabled;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getSessionsInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->sessionsInvalidatedAt;
    }

    public function setSessionsInvalidatedAt(?\DateTimeImmutable $sessionsInvalidatedAt): static
    {
        $this->sessionsInvalidatedAt = $sessionsInvalidatedAt;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // no plaintext credentials stored on this entity
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $user->getPassword() === $this->password
            && $user->getStatus() === $this->status;
    }
}
