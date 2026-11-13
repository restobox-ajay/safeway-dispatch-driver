<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation;

use App\Bundle\AuthImpersonation\DependencyInjection\Compiler\RegisterAuthImpersonationServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-impersonate-bundle — the admin/superadmin impersonation feature packaged as an OPTIONAL
 * Symfony bundle (FEATURE-142, C36 Option A). Reuses the auth-magic-link-bundle template
 * (FEATURE-140 / ADR-040): the bundle owns the user-firewall ImpersonationAuthenticator, the
 * /impersonate/* controller (start + user-exit + admin-exit), and its admin /config sub-page.
 * There is NO new table — impersonation is entirely PHP-session-key based, so ADR-014's custom
 * session-handoff mechanism is preserved unchanged; the bundle has no Entity/Repository/Migration.
 * Registered per deploy in config/bundles.php.
 *
 * When it is NOT registered none of its services/routes/config sub-page exist (404-when-uninstalled),
 * and core falls back to the null-object binding (App\Security\NullImpersonationAuthenticator). The
 * admin-side impersonate TRIGGER endpoints stay in core (they only write the session-key contract),
 * and their template buttons hide behind the bundle's `impersonation_available` Twig global. See ADR-041.
 */
final class AuthImpersonationBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object alias (app.impersonation_authenticator) to the real bundle
        // authenticator. A compiler pass — not the extension — because the app's services.yaml is
        // loaded AFTER bundle extensions and would otherwise win.
        $container->addCompilerPass(new RegisterAuthImpersonationServicesPass());
    }
}
