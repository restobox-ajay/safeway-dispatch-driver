<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminLoginHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-109: the dedicated login-history record for ADMIN logins — the admin-side mirror of
 * {@see LoginHistory}, following ADR-003 realm isolation.
 *
 * A SEPARATE table (admin_login_history), keyed on admin_id — NOT a shared login_history table with a
 * user_type discriminator (the same explicit-separation pattern as the admin_* token tables). One row
 * records one interactive admin login (ip, user_agent, fingerprint, created_at).
 *
 * The realm-agnostic columns are shared verbatim with the user store via {@see LoginHistoryColumns};
 * only the owner-id column (admin_id) is realm-specific.
 */
#[ORM\Entity(repositoryClass: AdminLoginHistoryRepository::class)]
#[ORM\Table(name: 'admin_login_history')]
#[ORM\Index(name: 'idx_admin_login_history_admin_id', columns: ['admin_id'])]
class AdminLoginHistory
{
    use LoginHistoryColumns;

    #[ORM\Column(name: 'admin_id', type: 'integer')]
    private int $adminId;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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
}
