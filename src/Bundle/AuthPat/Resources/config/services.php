<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Services for auth-pat-bundle (FEATURE-138). These are registered ONLY when the bundle is present,
 * so the app's `App\` autoregistration in config/services.yaml deliberately EXCLUDES src/Bundle/ —
 * a bundle class is a service exclusively via this file, i.e. exclusively when registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Entity/, Resources/, DependencyInjection/ and the Bundle class carry no service logic. The
    // controllers, the repository (ServiceEntityRepository, autoconfigured), and the authenticator
    // are all registered by this glob. The compiler pass then aliases UserTokenRevokerInterface to
    // the repository.
    $services->load('App\\Bundle\\AuthPat\\', \dirname(__DIR__, 2).'/')
        ->exclude([
            \dirname(__DIR__, 2).'/Entity/',
            \dirname(__DIR__, 2).'/Resources/',
            \dirname(__DIR__, 2).'/DependencyInjection/',
            \dirname(__DIR__, 2).'/AuthPatBundle.php',
        ]);
};
