<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Controller;

use App\Routing\RouteRequirement;
use App\Repository\UserRepository;
use App\Security\UserTwoFactorManagerInterface;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin-API "reset a user's 2FA" endpoint (spec: DELETE /api/admin/users/{id}/2fa). Moved out of
 * core AdminApiUserController into auth-2fa-bundle (review follow-up to FEATURE-139): this is a
 * 2fa-bundle endpoint, so it exists ONLY when the bundle is registered — with the bundle uninstalled the
 * route is absent from the router (proven by Auth2faBundleModularityTest), the honest form of
 * FEATURE-062 AC5. Mirrors the PAT revoke-tokens extraction.
 *
 * `#[IsGranted('ROLE_ADMIN')]` is mandatory on every admin-API controller (see the note in
 * config/packages/security.yaml): in separate-domain mode access_control may not run, so the class-level
 * guard is the load-bearing authorization.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/users')]
final class AdminApiTwoFactorController extends AbstractController
{
    #[Route('/{id}/2fa', name: 'app_api_admin_users_reset_2fa', requirements: ['id' => RouteRequirement::ID], methods: ['DELETE'])]
    public function resetTwoFactor(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserTwoFactorManagerInterface $twoFactor,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $twoFactor->disable($user);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_reset_2fa',
            'success',
            $user->getEmail()
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
