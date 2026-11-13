<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthMagicLink\AuthMagicLinkBundle}. Loads the bundle's own
 * services and prepends the app-level config the magic-link feature needs — all of which therefore
 * exist ONLY when the bundle is registered (FEATURE-140):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir;
 *   (b) the bundle-owned migrations path (migration-ownership pattern).
 *
 * The magic-link authenticator stays on the `user` firewall in the app's security.yaml (Symfony
 * requires all firewalls in one file). It references the stable alias `app.magic_link_authenticator`,
 * which core defaults to {@see \App\Security\NullMagicLinkAuthenticator} and the bundle's compiler
 * pass re-aliases to the real {@see \App\Bundle\AuthMagicLink\Security\MagicLinkAuthenticator}.
 */
final class AuthMagicLinkExtension extends Extension implements PrependExtensionInterface
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
                    'AuthMagicLink' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthMagicLink\\Entity',
                        'alias' => 'AuthMagicLink',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migration. A distinct namespace keeps it separate from the app's
        // DoctrineMigrations path; both paths are scanned together and run in timestamp order.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'App\\Bundle\\AuthMagicLink\\Migrations' => $migrationsDir,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_magic_link';
    }
}
