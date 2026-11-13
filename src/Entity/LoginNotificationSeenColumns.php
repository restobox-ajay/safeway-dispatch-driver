<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * FEATURE-108 (C8 dumb-data rule): the identical, realm-agnostic columns of a login-notification
 * recognition-memory record — the surrogate id, the per-mode `marker`, and the `created_at` stamp.
 *
 * Shared verbatim between the user store ({@see LoginNotificationSeen}, keyed on user_id) and the
 * admin store ({@see AdminLoginNotificationSeen}, keyed on admin_id). Only the owner-id column is
 * realm-specific and therefore lives on each entity, not here. This trait carries NO behaviour and
 * no owner reference — it is pure dumb data, exactly the sharing the review's C8 rule permits (no
 * abstract base entity, no cross-realm store interface).
 */
trait LoginNotificationSeenColumns
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $marker;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarker(): string
    {
        return $this->marker;
    }

    public function setMarker(string $marker): static
    {
        $this->marker = $marker;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
