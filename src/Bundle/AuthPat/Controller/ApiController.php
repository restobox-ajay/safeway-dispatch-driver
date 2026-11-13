<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiController extends AbstractController
{
    #[Route('/ping', name: 'app_api_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
