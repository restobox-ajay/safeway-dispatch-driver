<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Services for auth-webhook-bundle (FEATURE-141). These are registered ONLY when the bundle is
 * present, so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES
 * src/Bundle/ — a bundle class is a service exclusively via this file, i.e. exclusively when
 * registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Glob-registers the WebhookListener, the two dispatchers, the SendWebhookMessageHandler
    // (autoconfigured via #[AsMessageHandler]), the SsrfGuard, the WebhookDeliveryRepository
    // (ServiceEntityRepository) and the WebhookConfigPage. autoconfigure applies the
    // `auth.config_page` tag (from the ConfigPageProviderInterface attribute) to WebhookConfigPage so
    // ConfigPageRegistry collects it. The compiler pass then upgrades App\Service\WebhookDispatcherInterface.
    $services->load('App\\Bundle\\AuthWebhook\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Entity/',
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            \dirname(__DIR__, 2).'/AuthWebhookBundle.php',
        ]);
};
