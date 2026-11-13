<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthPat\AuthPatBundle;
use App\Kernel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The app kernel without auth-pat-bundle, plus one APPLICATION-level route under /api — the downstream app
 * that adds its own /api endpoint and relies on the documented "automatically protected" guarantee (issue #27).
 * The route is declared on the kernel itself: MicroKernelTrait imports the kernel file's #[Route] attributes.
 */
final class NoAuthPatAppApiKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthPatBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    #[Route('/api/app-orders', name: 'test_app_api_orders', methods: ['GET'])]
    public function appOrders(): JsonResponse
    {
        return new JsonResponse(['orders' => ['secret order data']]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authpat_app_api';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authpat_app_api';
    }
}
