<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Routes for auth-pat-bundle (FEATURE-138): the `/api` user-API entry and the `/account/tokens*`
 * management UI. Imported by App\Kernel::configureRoutes ONLY when the bundle is registered, so with
 * the bundle absent these routes do not exist and return 404 (AC4).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        \dirname(__DIR__, 2).'/Controller/',
        'attribute',
    );
};
