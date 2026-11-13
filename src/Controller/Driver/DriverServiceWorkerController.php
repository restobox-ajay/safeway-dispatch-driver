<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The driver app's service worker (FEATURE-194/203). A worker controls only pages under its own URL, so it is
 * served at /driver/sw.js; the file itself lives with the other app files in public/driver-app/ (no public
 * folder is named "driver", which would clash with the /driver pages on Apache/LiteSpeed). Public: the
 * browser re-checks it whether or not a driver is signed in.
 */
final class DriverServiceWorkerController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/driver-app/sw.js')] private readonly string $path,
    ) {}

    #[Route('/driver/sw.js', name: 'app_driver_service_worker', methods: ['GET'])]
    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->path, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Always re-checked, so a new version of the app reaches phones on their next visit.
            'Cache-Control' => 'no-cache',
        ]);

        return $response;
    }
}
