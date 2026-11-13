<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthImpersonation\AuthImpersonationBundle}. Loads the bundle's
 * own services and prepends the one app-level config the impersonation feature needs — all of which
 * therefore exist ONLY when the bundle is registered (FEATURE-142):
 *   (a) a Twig global `impersonation_available` = true, so the always-rendered core admin templates
 *       can guard their impersonate TRIGGER buttons on `{% if impersonation_available is defined and
 *       impersonation_available %}` (strict_variables is on). With the bundle absent the global is
 *       undefined ⇒ the buttons are hidden ⇒ no invitation to a broken flow.
 *
 * Unlike auth-magic-link-bundle there is NO doctrine ORM mapping and NO migrations path to prepend:
 * impersonation owns no table (it is session-key based, ADR-014).
 *
 * The impersonation authenticator stays on the `user` firewall in the app's security.yaml (Symfony
 * requires all firewalls in one file). It references the stable alias `app.impersonation_authenticator`,
 * which core defaults to {@see \App\Security\NullImpersonationAuthenticator} and the bundle's compiler
 * pass re-aliases to the real {@see \App\Bundle\AuthImpersonation\Security\ImpersonationAuthenticator}.
 */
final class AuthImpersonationExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Registered ONLY when the bundle is present, so the core admin templates' impersonate buttons
        // vanish cleanly with the bundle removed (the guard's `is defined` test then evaluates false).
        $container->prependExtensionConfig('twig', [
            'globals' => [
                'impersonation_available' => true,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_impersonation';
    }
}
