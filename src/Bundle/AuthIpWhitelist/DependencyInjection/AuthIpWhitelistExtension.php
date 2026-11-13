<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthIpWhitelist\AuthIpWhitelistBundle}. Loads the bundle's own services
 * and prepends the app-level config the IP-whitelist feature needs — all of which therefore exist ONLY when
 * the bundle is registered (FEATURE-146):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir (the user_ip_whitelist satellite);
 *   (b) the bundle-owned migrations path (migration-ownership pattern).
 *
 * The per-user override read/write stays behind one stable core port
 * (App\Security\IpWhitelistManagerInterface), which core defaults to a null-object and the bundle's compiler
 * pass re-aliases to the real service. The bundle owns no routes (the /admin/config sub-page is served by the
 * core AdminConfigController; login enforcement is an event listener, not a route), so — like the
 * auth-security / auth-password-policy bundles — there is nothing to register in Kernel::configureRoutes.
 */
final class AuthIpWhitelistExtension extends Extension implements PrependExtensionInterface
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
                    'AuthIpWhitelist' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthIpWhitelist\\Entity',
                        'alias' => 'AuthIpWhitelist',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migrations (dir lives under the bundle; registered only here, so they
        // run only when the bundle is enabled). The namespace is `IpWhitelistBundleMigrations`, NOT
        // `App\Bundle\AuthIpWhitelist\Migrations`, on purpose: Doctrine orders migrations across paths by
        // fully-qualified class name (strcmp), and the satellite migration must run AFTER the legacy
        // `DoctrineMigrations\...` ones that create `user` + its allowed_ips column (it relocates them). A
        // namespace sorting after "DoctrineMigrations" ('I' > 'D') achieves that. See ADR-046.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'IpWhitelistBundleMigrations' => $migrationsDir,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_ip_whitelist';
    }
}
