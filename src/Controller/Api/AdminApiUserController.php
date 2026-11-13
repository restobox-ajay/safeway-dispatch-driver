<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Routing\RouteRequirement;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Service\AuditLogger;
use App\Service\PasswordResetService;
use App\Service\UserAccountAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/users')]
final class AdminApiUserController extends AbstractController
{
    /**
     * Pages are clamped to 1..MAX_PAGE (issue #53): a huge ?page= saturated (int) to PHP_INT_MAX, and
     * (page - 1) * size became a float that the int-typed setFirstResult() rejected with a 500.
     */
    private const MAX_PAGE = 1_000_000;

    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE     = 100;

    #[Route('', name: 'app_api_admin_users_list', methods: ['GET'])]
    public function list(Request $request, UserRepository $userRepository): JsonResponse
    {
        $page = max(1, min((int) $request->query->get('page', '1'), self::MAX_PAGE));

        // per_page is caller-configurable but bounded, so a hostile value cannot force a
        // full-table scan (spec: "List users (paginated, filterable)").
        $perPage = (int) $request->query->get('per_page', (string) self::DEFAULT_PAGE_SIZE);
        $perPage = max(1, min($perPage, self::MAX_PAGE_SIZE));

        $filters = [];
        $email   = trim((string) $request->query->get('email', ''));
        if ($email !== '') {
            $filters['email'] = $email;
        }
        // Only apply a status filter for a recognised value; an unknown status is ignored
        // rather than silently returning a confusing empty page.
        $status = (string) $request->query->get('status', '');
        if ($status !== '' && AccountStatus::isValid($status)) {
            $filters['status'] = $status;
        }

        $total = $userRepository->countFiltered($filters);
        $users = $userRepository->findFilteredPaginated($filters, $page, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));

        return new JsonResponse([
            'data' => array_map($this->serializeUser(...), $users),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $pages,
            ],
        ]);
    }

    #[Route('', name: 'app_api_admin_users_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserAccountAdminService $userService,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        // The shared service owns validation, the racy-duplicate catch, the atomic audit, and
        // password-lifecycle bookkeeping (FEATURE-103 AC1). Same one path the web surface uses.
        $result = $userService->create(
            \is_array($data) ? $data : [],
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            $request->getClientIp() ?? '0.0.0.0',
        );

        if (!$result->isSuccess()) {
            return new JsonResponse(['errors' => $result->errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->serializeUser($result->user), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_admin_users_detail', requirements: ['id' => RouteRequirement::ID], methods: ['GET'])]
    public function detail(int $id, UserRepository $userRepository): JsonResponse
    {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/{id}', name: 'app_api_admin_users_update', requirements: ['id' => RouteRequirement::ID], methods: ['PATCH'])]
    public function update(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserAccountAdminService $userService,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!\is_array($data)) {
            $data = [];
        }

        // PATCH semantics: forward only the keys the client actually sent, so the shared service
        // validates/applies exactly those fields (it also owns the atomic audit + recovery-token
        // invalidation on deactivation).
        $changes = [];
        foreach (['email', 'name', 'role', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key];
            }
        }

        $result = $userService->update(
            $user,
            $changes,
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            $request->getClientIp() ?? '0.0.0.0',
        );

        if (!$result->isSuccess()) {
            return new JsonResponse(['errors' => $result->errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/{id}', name: 'app_api_admin_users_delete', requirements: ['id' => RouteRequirement::ID], methods: ['DELETE'])]
    public function delete(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserAccountAdminService $userService,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        // Soft delete (ADR-020 / FEATURE-110): the shared service sets status='inactive', writes
        // the atomic audit row, and kills outstanding recovery tokens.
        $userService->delete($user, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/activate', name: 'app_api_admin_users_activate', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function activate(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $user->setStatus('active');
        $em->flush();

        $this->audit($auditLogger, $request, 'admin.user_activate', $user->getEmail());

        return new JsonResponse(['status' => 'ok', 'user' => $this->serializeUser($user)]);
    }

    #[Route('/{id}/deactivate', name: 'app_api_admin_users_deactivate', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function deactivate(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserAccountAdminService $userService,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        // Deactivation must actually cut access now, not just at next login. The shared service
        // sets status='inactive', drops sessions, revokes PATs, invalidates remember-me cookies +
        // recovery tokens, and writes the audit row.
        $userService->deactivate($user, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0');

        return new JsonResponse(['status' => 'ok', 'user' => $this->serializeUser($user)]);
    }

    #[Route('/{id}/force-logout', name: 'app_api_admin_users_force_logout', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function forceLogout(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserSessionRepository $sessionRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $sessionRepository->deleteAllByUserId((int) $user->getId());

        // Dropping session rows does not stop a remember-me cookie from re-authenticating;
        // bump the sessions-invalidated marker (folded into the remember-me HMAC) so every
        // outstanding remember-me cookie for this user is invalidated.
        $user->setSessionsInvalidatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->audit($auditLogger, $request, 'admin.user_force_logout', $user->getEmail());

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/{id}/password-reset', name: 'app_api_admin_users_password_reset', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function passwordReset(
        int $id,
        Request $request,
        UserRepository $userRepository,
        PasswordResetService $passwordResetService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $passwordResetService->sendResetLink($user);

        $this->audit($auditLogger, $request, 'admin.user_password_reset', $user->getEmail());

        return new JsonResponse(['status' => 'ok']);
    }

    // NOTE: the three bundle-specific admin-API endpoints are NOT here — each lives in its own bundle and
    // is registered ONLY when that bundle is installed (FEATURE-139 + review follow-up 2026-07-09; route
    // absence when the bundle is uninstalled is proven by each bundle's *ModularityTest):
    //   DELETE /admin-api/users/{id}/tokens -> App\Bundle\AuthPat\Controller\AdminApiTokenController
    //   POST   /admin-api/users/{id}/unlock -> App\Bundle\AuthSecurity\Controller\AdminApiUnlockController
    //   DELETE /admin-api/users/{id}/2fa    -> App\Bundle\Auth2fa\Controller\AdminApiTwoFactorController

    /** @param string $target the affected account's email, recorded as the audit context (issue #46) */
    private function audit(AuditLogger $auditLogger, Request $request, string $action, string $target): void
    {
        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            $action,
            'success',
            $target
        );
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'         => $user->getId(),
            'email'      => $user->getEmail(),
            'name'       => $user->getName(),
            'status'     => $user->getStatus(),
            'roles'      => $user->getRoles(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
