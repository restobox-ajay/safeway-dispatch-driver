<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\Auth2fa\Auth2faBundle}. Loads the bundle's own services and
 * prepends the app-level config the user-2FA feature needs — all of which therefore exist ONLY when the
 * bundle is registered (FEATURE-143):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir (the two_factor_settings satellite);
 *   (b) the bundle-owned migrations path (migration-ownership pattern);
 *   (c) a Twig global `two_factor_available` = true, so the always-rendered core templates
 *       (_user_nav, account/settings) guard their user-2FA links on
 *       `{% if two_factor_available is defined and two_factor_available %}` — with the bundle absent
 *       the global is undefined and the links (pointing at now-404 bundle routes) are hidden.
 */
final class Auth2faExtension extends Extension implements PrependExtensionInterface
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
                    'Auth2fa' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\Auth2fa\\Entity',
                        'alias' => 'Auth2fa',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migration (dir lives under the bundle; registered only here, so it
        // runs only when the bundle is enabled). The namespace is `TwoFactorMigrations`, NOT
        // `App\Bundle\Auth2fa\Migrations`, on purpose: Doctrine orders migrations across paths by
        // fully-qualified class name (strcmp), and this satellite migration must run AFTER the legacy
        // `DoctrineMigrations\...` ones that create `user` + its totp columns (it relocates them). A
        // namespace sorting after "DoctrineMigrations" ('T' > 'D') achieves that. See ADR-043 and the
        // migration's own header.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'TwoFactorMigrations' => $migrationsDir,
            ],
        ]);

        // (c) Registered ONLY when the bundle is present, so the core user templates' 2FA links vanish
        // cleanly with the bundle removed (the guard's `is defined` test then evaluates false).
        $container->prependExtensionConfig('twig', [
            'globals' => [
                'two_factor_available' => true,
            ],
        ]);
    }

    public function getAlias(): string
    {
        // Must equal the underscored bundle name; "Auth2faBundle" has no case boundary at "2fa", so
        // the expected alias is "auth2fa" (not "auth_2fa").
        return 'auth2fa';
    }
}
