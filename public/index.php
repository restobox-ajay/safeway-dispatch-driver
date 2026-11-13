<?php

use App\Kernel;

// Maintenance mode (ADR-069): runs before the autoloader/Kernel so it works mid-update.
require_once dirname(__DIR__).'/src/Maintenance/MaintenanceGate.php';
(new App\Maintenance\MaintenanceGate(dirname(__DIR__)))->run();

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
