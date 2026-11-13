<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Admin;

/**
 * The single source of truth for the tech-support visibility rule (ADR-050 / FEATURE-149).
 *
 * ROLE_TECH_SUPPORT is the maintainer tier: superadmin powers via role_hierarchy, but the
 * accounts holding it are invisible to every other admin — including superadmins — on all
 * admin-management surfaces (list, edit, delete, reset, impersonate). Tech-support admins
 * see everyone, each other included. A hidden target must be indistinguishable from a
 * nonexistent one, so callers translate !canSee() into the same 404 as an unknown id.
 *
 * Deliberately NOT hidden (Ken, 2026-07-09): audit-log rows keep their real actor emails —
 * the audit trail stays honest for everyone.
 */
final class TechSupportVisibility
{
    public function isTechSupport(Admin $admin): bool
    {
        return in_array('ROLE_TECH_SUPPORT', $admin->getRoles(), true);
    }

    public function canSee(Admin $viewer, Admin $target): bool
    {
        return $this->isTechSupport($viewer) || !$this->isTechSupport($target);
    }

    /**
     * @param Admin[] $admins
     * @return Admin[]
     */
    public function filterVisible(Admin $viewer, array $admins): array
    {
        return array_values(array_filter(
            $admins,
            fn (Admin $target): bool => $this->canSee($viewer, $target)
        ));
    }
}
