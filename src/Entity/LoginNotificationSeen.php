<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginNotificationSeenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-107 (review C14): the dedicated recognition-memory record for USER login notifications.
 *
 * One row means "we have already notified this user about this device", keyed on user_id + a
 * per-mode marker (the fingerprint under recognition_mode=fingerprint, the IP under ip_only). It is
 * a single-purpose store, kept separate from both login_history (which the notifier used to race on)
 * and audit_log (which retention prunes) so notification behaviour never depends on either.
 *
 * FEATURE-108: the realm-agnostic columns (id, marker, created_at) are shared verbatim with the
 * admin store ({@see AdminLoginNotificationSeen}) via {@see LoginNotificationSeenColumns}; only the
 * owner-id column (user_id) is realm-specific and stays here.
 */
#[ORM\Entity(repositoryClass: LoginNotificationSeenRepository::class)]
#[ORM\Table(name: 'login_notification_seen')]
#[ORM\Index(name: 'idx_login_notification_seen_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_login_notification_seen_user_marker', columns: ['user_id', 'marker'])]
class LoginNotificationSeen
{
    use LoginNotificationSeenColumns;

    #[ORM\Column(name: 'user_id', type: 'integer')]
    private int $userId;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }
}
