<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy;

use App\Bundle\AuthPasswordPolicy\DependencyInjection\Compiler\RegisterAuthPasswordPolicyServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-password-policy-bundle — the password-policy feature (strength rules, expiry, reuse-prevention)
 * packaged as an OPTIONAL Symfony bundle (FEATURE-145, C36 Option A). The THIRD satellite-table extraction:
 * it owns a NEW `password_meta` table (user_id FK) that replaces the old `password_changed_at` column on
 * `user`, owns the `password_history` table (mapping moved in), and moves the policy validator, the reuse
 * history service, the expiry checker + listener, and its admin /config sub-page into itself.
 *
 * When it is NOT registered none of its services/config sub-page exist, its migration never runs (so
 * password_meta never exists and password_changed_at is never dropped) and core falls back to the
 * null-object binding (App\Security\NullPasswordPolicyManager) — so no password is ever validated,
 * expired, or reuse-checked. See ADR-045.
 */
final class AuthPasswordPolicyBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object alias (PasswordPolicyManagerInterface) to the real bundle service.
        // A compiler pass — not the extension — because the app's services.yaml is loaded AFTER bundle
        // extensions and would otherwise win.
        $container->addCompilerPass(new RegisterAuthPasswordPolicyServicesPass());
    }
}
