<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Controller;

use App\Routing\RouteRequirement;
use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin-API "revoke all of a user's PATs" endpoint (FEATURE-062 AC2). Moved out of core
 * `AdminApiUserController` into auth-pat-bundle (FEATURE-139 reconciliation): this is a PAT-bundle
 * endpoint, so it must exist ONLY when the bundle is registered. With the bundle uninstalled the
 * route is absent from the router (proven by AuthPatBundleModularityTest) — the honest form of
 * FEATURE-062 AC5 "endpoints are only registered when their respective bundle is installed", which
 * previously was faked as always-present-in-the-monorepo.
 *
 * `#[IsGranted('ROLE_ADMIN')]` is mandatory on every admin-API controller (see the note in
 * config/packages/security.yaml): in separate-domain mode access_control may not run, so the
 * class-level guard is the load-bearing authorization.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/users')]
final class AdminApiTokenController extends AbstractController
{
    #[Route('/{id}/tokens', name: 'app_api_admin_users_revoke_tokens', requirements: ['id' => RouteRequirement::ID], methods: ['DELETE'])]
    public function revokeTokens(
        int $id,
        Request $request,
        UserRepository $userRepository,
        PersonalAccessTokenRepository $tokenRepository,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $tokenRepository->revokeAllByUserId((int) $user->getId());

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_revoke_tokens',
            'success',
            $user->getEmail()
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
