<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Routes for auth-2fa-bundle (FEATURE-143): the user TOTP setup / disable / challenge flow
 * (app_2fa_setup, app_2fa_disable, app_2fa_challenge). Imported by App\Kernel::configureRoutes ONLY
 * when the bundle is registered, so with the bundle absent these routes do not exist and return 404 (AC4).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        \dirname(__DIR__, 2).'/Controller/',
        'attribute',
    );
};
