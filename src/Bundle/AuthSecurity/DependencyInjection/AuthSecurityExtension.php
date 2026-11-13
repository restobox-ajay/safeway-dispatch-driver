<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthSecurity\AuthSecurityBundle}. Loads the bundle's own services and
 * prepends the app-level config the security feature needs — all of which therefore exist ONLY when the
 * bundle is registered (FEATURE-144):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir (the account_lockouts satellite);
 *   (b) the bundle-owned migrations path (migration-ownership pattern).
 *
 * The rate-limit / lockout behaviour stays behind two stable core ports
 * (App\Security\EndpointRateLimiterInterface + App\Security\AccountLockManagerInterface), which core
 * defaults to null-objects and the bundle's compiler pass re-aliases to the real services. The bundle owns
 * the admin-API unlock endpoint (POST /admin-api/users/{id}/unlock) in Controller/, registered via
 * Resources/config/routes.php — imported by Kernel::configureRoutes ONLY when the bundle is enabled, so
 * admin unlock 404s when the bundle is uninstalled (like the PAT / magic-link / 2fa bundles). The
 * /admin/config sub-page is still served by the core AdminConfigController; the login rate-limit
 * enforcement is a CheckPassport listener, not a route.
 */
final class AuthSecurityExtension extends Extension implements PrependExtensionInterface
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
                    'AuthSecurity' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthSecurity\\Entity',
                        'alias' => 'AuthSecurity',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migrations (dir lives under the bundle; registered only here, so they
        // run only when the bundle is enabled). The namespace is `SecurityBundleMigrations`, NOT
        // `App\Bundle\AuthSecurity\Migrations`, on purpose: Doctrine orders migrations across paths by
        // fully-qualified class name (strcmp), and the satellite migration must run AFTER the legacy
        // `DoctrineMigrations\...` ones that create `user` + its locked_until column (it relocates them).
        // A namespace sorting after "DoctrineMigrations" ('S' > 'D') achieves that. See ADR-044.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'SecurityBundleMigrations' => $migrationsDir,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_security';
    }
}
