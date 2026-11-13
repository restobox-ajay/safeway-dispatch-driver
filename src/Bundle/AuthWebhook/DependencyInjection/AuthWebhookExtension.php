<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * DI extension for {@see \App\Bundle\AuthWebhook\AuthWebhookBundle}. Loads the bundle's own services
 * and prepends the app-level config the webhook feature needs — all of which therefore exist ONLY when
 * the bundle is registered (FEATURE-141):
 *   (a) the doctrine ORM mapping for the bundle's Entity dir (WebhookDelivery);
 *   (b) the bundle-owned migrations path (migration-ownership pattern).
 *
 * The webhook dispatcher stays behind the stable core alias App\Service\WebhookDispatcherInterface
 * (injected by the three core auth flows). Core defaults it to {@see \App\Service\NullWebhookDispatcher}
 * and the bundle's compiler pass re-aliases it to the real
 * {@see \App\Bundle\AuthWebhook\Service\MessengerWebhookDispatcher}.
 */
final class AuthWebhookExtension extends Extension implements PrependExtensionInterface
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
                    'AuthWebhook' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $entityDir,
                        'prefix' => 'App\\Bundle\\AuthWebhook\\Entity',
                        'alias' => 'AuthWebhook',
                    ],
                ],
            ],
        ]);

        // (b) The bundle owns its migration. A distinct namespace keeps it separate from the app's
        // DoctrineMigrations path; both paths are scanned together and run in timestamp order.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'App\\Bundle\\AuthWebhook\\Migrations' => $migrationsDir,
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'auth_webhook';
    }
}
