<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Services for auth-ip-whitelist-bundle (FEATURE-146). These are registered ONLY when the bundle is present,
 * so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES src/Bundle/ — a bundle
 * class is a service exclusively via this file, i.e. exclusively when registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // The login listener, the façade manager, the ServiceEntityRepository and the ConfigPage are all
    // registered by this glob. autoconfigure applies the `auth.config_page` tag (from the
    // ConfigPageProviderInterface attribute) to IpWhitelistConfigPage so ConfigPageRegistry collects it, and
    // the `kernel.event_listener` tag (from the AsEventListener attribute) to IpWhitelistListener. The
    // compiler pass then aliases the core port to IpWhitelistManager.
    $services->load('App\\Bundle\\AuthIpWhitelist\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Entity/',
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            // The migrations live here but declare the `IpWhitelistBundleMigrations` namespace (so they
            // sort after DoctrineMigrations — see the migration header), which does NOT match this glob's
            // `App\Bundle\AuthIpWhitelist\` prefix; excluding the dir keeps the service loader from choking.
            \dirname(__DIR__, 2).'/Migrations/',
            \dirname(__DIR__, 2).'/AuthIpWhitelistBundle.php',
        ]);
};
