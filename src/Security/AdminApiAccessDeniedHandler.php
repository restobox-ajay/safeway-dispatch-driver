<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * An authenticated admin API caller whose role is not enough (e.g. a plain admin calling a tech-support-only
 * endpoint) gets the same JSON error shape as every other admin API failure, not an HTML error page.
 */
final class AdminApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
    }
}
