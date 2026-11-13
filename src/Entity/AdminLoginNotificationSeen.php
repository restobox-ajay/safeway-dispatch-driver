<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminLoginNotificationSeenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-108: the dedicated recognition-memory record for ADMIN login notifications — the admin-side
 * mirror of {@see LoginNotificationSeen}, following ADR-003 realm isolation.
 *
 * A SEPARATE table (admin_login_notification_seen), keyed on admin_id — NOT a shared login-notification
 * table with a user_type discriminator (the same explicit-separation pattern as the admin_* token
 * tables). One row means "we have already notified this admin about this device", keyed on admin_id + a
 * per-mode marker (fingerprint under admin_recognition_mode=fingerprint, IP under ip_only).
 *
 * The realm-agnostic columns (id, marker, created_at) are shared verbatim with the user store via
 * {@see LoginNotificationSeenColumns}; only the owner-id column (admin_id) is realm-specific.
 */
#[ORM\Entity(repositoryClass: AdminLoginNotificationSeenRepository::class)]
#[ORM\Table(name: 'admin_login_notification_seen')]
#[ORM\Index(name: 'idx_admin_login_notification_seen_admin_id', columns: ['admin_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_admin_login_notification_seen_admin_marker', columns: ['admin_id', 'marker'])]
class AdminLoginNotificationSeen
{
    use LoginNotificationSeenColumns;

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
