<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink;

use App\Bundle\AuthMagicLink\DependencyInjection\Compiler\RegisterAuthMagicLinkServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-magic-link-bundle — the passwordless (magic-link) login feature packaged as an OPTIONAL
 * Symfony bundle (FEATURE-140, C36 Option A). Reuses the auth-pat-bundle template (FEATURE-138 /
 * ADR-010): the bundle owns the MagicLinkToken entity, repository, the user-firewall authenticator,
 * the /magic-link* controller, its admin /config sub-page, its migration and doctrine mapping.
 * Registered per deploy in config/bundles.php.
 *
 * When it is NOT registered none of its services/routes/config sub-page exist (404-when-uninstalled),
 * and core falls back to the null-object bindings (App\Security\NullMagicLinkAuthenticator +
 * App\Service\NullMagicLinkTokenMaintainer). See ADR-040.
 */
final class AuthMagicLinkBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object aliases (app.magic_link_authenticator +
        // MagicLinkTokenMaintainerInterface) to the real bundle services. A compiler pass — not the
        // extension — because the app's services.yaml is loaded AFTER bundle extensions and would
        // otherwise win.
        $container->addCompilerPass(new RegisterAuthMagicLinkServicesPass());
    }
}
