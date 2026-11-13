<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Services for auth-password-policy-bundle (FEATURE-145). These are registered ONLY when the bundle is
 * present, so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES src/Bundle/ —
 * a bundle class is a service exclusively via this file, i.e. exclusively when registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // The policy validator, reuse-history service, expiry checker, the façade manager, the expiry listener,
    // the ServiceEntityRepositories and the ConfigPage are all registered by this glob. autoconfigure
    // applies the `auth.config_page` tag (from the ConfigPageProviderInterface attribute) to
    // PasswordPolicyConfigPage so ConfigPageRegistry collects it, and the `kernel.event_listener` tag (from
    // the AsEventListener attribute) to PasswordExpiryListener. The compiler pass then aliases the core port
    // to PasswordPolicyManager.
    $services->load('App\\Bundle\\AuthPasswordPolicy\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Entity/',
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            // The migrations live here but declare the `PasswordPolicyBundleMigrations` namespace (so they
            // sort after DoctrineMigrations — see the migration header), which does NOT match this glob's
            // `App\Bundle\AuthPasswordPolicy\` prefix; excluding the dir keeps the service loader from choking.
            \dirname(__DIR__, 2).'/Migrations/',
            \dirname(__DIR__, 2).'/AuthPasswordPolicyBundle.php',
        ]);
};
