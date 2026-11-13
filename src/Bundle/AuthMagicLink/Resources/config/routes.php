<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Routes for auth-magic-link-bundle (FEATURE-140): the /magic-link request/check/verify flow.
 * Imported by App\Kernel::configureRoutes ONLY when the bundle is registered, so with the bundle
 * absent these routes do not exist and return 404 (AC3).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        \dirname(__DIR__, 2).'/Controller/',
        'attribute',
    );
};
