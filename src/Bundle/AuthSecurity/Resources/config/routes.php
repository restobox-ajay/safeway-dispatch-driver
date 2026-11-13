<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Routes for auth-security-bundle: the admin-API "unlock account" endpoint
 * (app_api_admin_users_unlock, POST /admin-api/users/{id}/unlock). Imported by
 * App\Kernel::configureRoutes ONLY when the bundle is registered, so with the bundle absent this route
 * does not exist and returns 404 (proven by AuthSecurityBundleModularityTest).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        \dirname(__DIR__, 2).'/Controller/',
        'attribute',
    );
};
