<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Services for auth-magic-link-bundle (FEATURE-140). These are registered ONLY when the bundle is
 * present, so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES
 * src/Bundle/ — a bundle class is a service exclusively via this file, i.e. exclusively when
 * registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Controller, authenticator, repository (ServiceEntityRepository) and the ConfigPage are all
    // registered by this glob. autoconfigure applies the `auth.config_page` tag (from the
    // ConfigPageProviderInterface attribute) to MagicLinkConfigPage so ConfigPageRegistry collects it.
    // The compiler pass then aliases app.magic_link_authenticator + MagicLinkTokenMaintainerInterface.
    $services->load('App\\Bundle\\AuthMagicLink\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Entity/',
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            \dirname(__DIR__, 2).'/AuthMagicLinkBundle.php',
        ]);
};
