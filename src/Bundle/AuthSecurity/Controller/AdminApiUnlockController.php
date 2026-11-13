<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Controller;

use App\Routing\RouteRequirement;
use App\Repository\UserRepository;
use App\Security\AccountLockManagerInterface;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin-API "unlock a locked account" endpoint (spec: POST /api/admin/users/{id}/unlock). Moved out
 * of core AdminApiUserController into auth-security-bundle (review follow-up to FEATURE-139): this is a
 * security-bundle endpoint, so it exists ONLY when the bundle is registered — with the bundle uninstalled
 * the route is absent from the router (proven by AuthSecurityBundleModularityTest). Mirrors the PAT
 * revoke-tokens extraction.
 *
 * `#[IsGranted('ROLE_ADMIN')]` is mandatory on every admin-API controller (see the note in
 * config/packages/security.yaml).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/users')]
final class AdminApiUnlockController extends AbstractController
{
    #[Route('/{id}/unlock', name: 'app_api_admin_users_unlock', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function unlock(
        int $id,
        Request $request,
        UserRepository $userRepository,
        AccountLockManagerInterface $lockManager,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        // Clear the lockout via the port (deletes the account_lockouts row); a no-op when already unlocked.
        $lockManager->unlock($user);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_unlock',
            'success',
            $user->getEmail()
        );

        return new JsonResponse(['status' => 'ok']);
    }
}
