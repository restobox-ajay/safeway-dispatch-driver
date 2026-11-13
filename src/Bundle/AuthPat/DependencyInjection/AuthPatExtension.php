<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthPat\AuthPatBundle}. Loads the bundle's own services and
 * prepends the app-level config the PAT feature needs — all of which therefore exist ONLY when the
 * bundle is registered (FEATURE-138):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir;
 *   (b) the bundle-owned migrations path (migration-ownership pattern, AC2);
 *   (c) a Twig global `pat_available` = true, so the always-rendered core user sidebar can guard its
 *       "API Tokens" link (a bundle route) on `{% if pat_available is defined and pat_available %}` — with
 *       the bundle removed the global is undefined and the link disappears instead of 500ing (issue #14).
 *
 * The stateless `api` firewall itself stays in the app's security.yaml (Symfony requires all
 * firewalls in one file, so it cannot be prepended here). It references the stable alias
 * `app.api_authenticator`, which core defaults to {@see \App\Security\NullApiAuthenticator} and the
 * bundle's compiler pass re-aliases to {@see \App\Bundle\AuthPat\Security\TokenAuthenticator}. With
 * the bundle absent the null authenticator's supports() returns false, so /api falls through to
 * routing and 404s (the /api routes are bundle-owned).
 */
final class AuthPatExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $entityDir = \dirname(__DIR__).'/Entity';
        $migrationsDir = \dirname(__DIR__).'/Migrations';

        // (a) Map the bundle's own entity. is_bundle:false + explicit dir mirrors the app's `App`
        // mapping style (config/packages/doctrine.yaml) rather than relying on auto_mapping.
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AuthPat' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthPat\\Entity',
                        'alias' => 'AuthPat',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migration. A distinct namespace keeps it separate from the app's
        // DoctrineMigrations path; both paths are scanned together and run in timestamp order.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'App\\Bundle\\AuthPat\\Migrations' => $migrationsDir,
            ],
        ]);

        // (c) Registered ONLY when the bundle is present (see the class docblock).
        $container->prependExtensionConfig('twig', [
            'globals' => [
                'pat_available' => true,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_pat';
    }
}
