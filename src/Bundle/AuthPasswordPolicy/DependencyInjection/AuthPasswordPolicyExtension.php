<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthPasswordPolicy\AuthPasswordPolicyBundle}. Loads the bundle's own
 * services and prepends the app-level config the password-policy feature needs — all of which therefore
 * exist ONLY when the bundle is registered (FEATURE-145):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir (the password_meta satellite + password_history);
 *   (b) the bundle-owned migrations path (migration-ownership pattern).
 *
 * The policy/expiry/reuse behaviour stays behind one stable core port
 * (App\Security\PasswordPolicyManagerInterface), which core defaults to a null-object and the bundle's
 * compiler pass re-aliases to the real service. The bundle owns no routes (the change-password /
 * forced-change routes stay in core AccountController; /admin/config is served by the core controller), so —
 * unlike the PAT / magic-link / 2fa bundles — there is nothing to register in Kernel::configureRoutes.
 */
final class AuthPasswordPolicyExtension extends Extension implements PrependExtensionInterface
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

        // (a) Map the bundle's own entities. is_bundle:false + explicit dir mirrors the app's `App`
        // mapping style (config/packages/doctrine.yaml) rather than relying on auto_mapping.
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AuthPasswordPolicy' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthPasswordPolicy\\Entity',
                        'alias' => 'AuthPasswordPolicy',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migrations (dir lives under the bundle; registered only here, so they
        // run only when the bundle is enabled). The namespace is `PasswordPolicyBundleMigrations`, NOT
        // `App\Bundle\AuthPasswordPolicy\Migrations`, on purpose: Doctrine orders migrations across paths by
        // fully-qualified class name (strcmp), and the satellite migration must run AFTER the legacy
        // `DoctrineMigrations\...` ones that create `user` + its password_changed_at column (it relocates
        // them). A namespace sorting after "DoctrineMigrations" ('P' > 'D') achieves that. See ADR-045.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'PasswordPolicyBundleMigrations' => $migrationsDir,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_password_policy';
    }
}
