<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Services for auth-impersonate-bundle (FEATURE-142). These are registered ONLY when the bundle is
 * present, so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES
 * src/Bundle/ — a bundle class is a service exclusively via this file, i.e. exclusively when
 * registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Controller, authenticator and the ConfigPage are all registered by this glob. autoconfigure
    // applies the `auth.config_page` tag (from the ConfigPageProviderInterface attribute) to
    // ImpersonateConfigPage so ConfigPageRegistry collects it, and `controller.service_arguments`
    // to the controller. The compiler pass then aliases app.impersonation_authenticator.
    $services->load('App\\Bundle\\AuthImpersonation\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            \dirname(__DIR__, 2).'/AuthImpersonationBundle.php',
        ]);
};
