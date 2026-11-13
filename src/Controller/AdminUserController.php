<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountLockManagerInterface;
use App\Security\IpWhitelistManagerInterface;
use App\Security\UserTokenRevokerInterface;
use App\Security\UserTwoFactorManagerInterface;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use App\Service\PasswordResetService;
use App\Service\UserAccountAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
class AdminUserController extends AbstractController
{
    private const PAGE_SIZE = 10;

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function list(
        Request $request,
        UserRepository $userRepository,
        UserTokenRevokerInterface $tokenRevoker,
        UserTwoFactorManagerInterface $twoFactor,
        AccountLockManagerInterface $lockManager,
        ConfigService $config,
    ): Response {
        $page  = max(1, (int) $request->query->get('page', '1'));
        $total = $userRepository->countAll();
        $users = $userRepository->findPaginated($page, self::PAGE_SIZE);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        $userIds     = array_map(static fn (User $u) => $u->getId(), $users);
        $tokenCounts = $tokenRevoker->countActiveByUserIds(
            array_values(array_filter($userIds, static fn ($id) => $id !== null))
        );

        // The "Reset 2FA" button shows only for users who actually have 2FA enabled. The enrolment now
        // lives in the auth-2fa-bundle satellite, read via the core port — so this id set is empty (and
        // the button never renders) when the bundle is absent.
        $twoFactorEnabledIds = array_values(array_filter(
            $userIds,
            static fn ($id, $i) => $id !== null && $twoFactor->isEnabled($users[$i]),
            ARRAY_FILTER_USE_BOTH,
        ));

        // The "Locked" indicator and "Unlock" button show only for users who are currently locked. The
        // lockout now lives in the auth-security-bundle satellite, read via the core port — so this id set
        // is empty (and neither renders) when the bundle is absent (FEATURE-144 / ADR-044).
        $lockedIds = array_values(array_filter(
            $userIds,
            static fn ($id, $i) => $id !== null && $lockManager->isLocked($users[$i]),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $this->render('admin/users/list.html.twig', [
            'users'                => $users,
            'currentPage'          => $page,
            'totalPages'           => $pages,
            'total'                => $total,
            'tokenCounts'          => $tokenCounts,
            'two_factor_enabled_ids' => $twoFactorEnabledIds,
            'locked_user_ids'      => $lockedIds,
            // "Allow Impersonation" toggle (issue #9); the impersonate button is hidden when off.
            'impersonation_enabled' => $config->getBool('impersonate.enabled', true),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserAccountAdminService $userService,
        IpWhitelistManagerInterface $ipWhitelist,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_edit', (string) $request->request->get('_token', ''))) {
                $errors['csrf'] = 'Invalid CSRF token.';
            } else {
                // The web form always submits every field; the shared service validates/applies
                // each present key and owns the atomic audit + recovery-token invalidation.
                $result = $userService->update($user, [
                    'email'       => $request->request->get('email', ''),
                    'name'        => $request->request->get('name', ''),
                    'role'        => $request->request->get('role', 'ROLE_USER'),
                    'status'      => $request->request->get('status', 'active'),
                    'allowed_ips' => $request->request->get('allowed_ips', ''),
                ], $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0');

                if ($result->isSuccess()) {
                    $this->addFlash('success', 'User updated successfully.');

                    return $this->redirectToRoute('app_admin_users');
                }

                $errors = $result->errors;
            }
        }

        return $this->render('admin/users/edit.html.twig', [
            'user'        => $user,
            'errors'      => $errors,
            // The per-user IP-whitelist override lives in the auth-ip-whitelist-bundle satellite, not on
            // `user` (FEATURE-146): read it through the port (empty when the bundle is absent).
            'allowed_ips' => $ipWhitelist->getAllowedIps($user) ?? '',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_users_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserAccountAdminService $userService,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Soft delete (ADR-020 / FEATURE-110): the shared service sets status='inactive' + writes
        // the atomic audit row + kills outstanding recovery tokens; the row and its history survive.
        $userService->delete($user, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0');

        $this->addFlash('success', 'User deleted successfully.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/password-reset', name: 'app_admin_users_password_reset', methods: ['POST'])]
    public function passwordReset(
        int $id,
        Request $request,
        UserRepository $userRepository,
        PasswordResetService $passwordResetService,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_password_reset_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $passwordResetService->sendResetLink($user);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_password_reset',
            'success',
            $user->getEmail()
        );

        $this->addFlash('success', 'Password reset email sent to ' . $user->getEmail() . '.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/reset-2fa', name: 'app_admin_users_reset_2fa', methods: ['POST'])]
    public function resetTwoFactor(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserTwoFactorManagerInterface $twoFactor,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_reset_2fa_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$twoFactor->isEnabled($user)) {
            $this->addFlash('info', '2FA is not enabled for ' . $user->getEmail() . '.');
            return $this->redirectToRoute('app_admin_users');
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

        $this->addFlash('success', '2FA has been reset for ' . $user->getEmail() . '.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/unlock', name: 'app_admin_users_unlock', methods: ['POST'])]
    public function unlock(
        int $id,
        Request $request,
        UserRepository $userRepository,
        AccountLockManagerInterface $lockManager,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_unlock_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Clear the lockout via the port (deletes the account_lockouts row). A no-op when the bundle is
        // absent — but the button that reaches this action only renders when a lockout exists.
        $lockManager->unlock($user);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_unlock',
            'success',
            $user->getEmail()
        );

        $this->addFlash('success', 'Account unlocked for ' . $user->getEmail() . '.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/revoke-tokens', name: 'app_admin_users_revoke_tokens', methods: ['POST'])]
    public function revokeAllTokens(
        int $id,
        Request $request,
        UserRepository $userRepository,
        UserTokenRevokerInterface $tokenRevoker,
        AuditLogger $auditLogger,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_revoke_tokens_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tokenRevoker->revokeAllByUserId($id);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_revoke_tokens',
            'success',
            $user->getEmail()
        );

        $this->addFlash('success', 'All active tokens revoked for ' . $user->getEmail() . '.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/impersonate-start', name: 'app_admin_users_impersonate_start', methods: ['POST'])]
    public function impersonateStart(
        int $id,
        Request $request,
        UserRepository $userRepository,
        AuditLogger $auditLogger,
        ConfigService $config,
    ): Response {
        $user = $userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isCsrfTokenValid('admin_user_impersonate_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // "Allow Impersonation" (issue #9): nothing is queued or audited when it is off.
        if (!$config->getBool('impersonate.enabled', true)) {
            $this->addFlash('error', 'Impersonation is disabled (Config → Impersonation).');
            return $this->redirectToRoute('app_admin_users');
        }

        $adminEmail = $this->getUser()?->getUserIdentifier() ?? 'unknown';

        $request->getSession()->set('_impersonation_request', [
            'userId'     => $user->getId(),
            'adminEmail' => $adminEmail,
        ]);

        $auditLogger->log(
            $adminEmail,
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.impersonate_start',
            'success',
            $user->getEmail()
        );

        return $this->redirectToRoute('app_impersonate_start');
    }

    #[Route('/new', name: 'app_admin_users_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserAccountAdminService $userService,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_create', (string) $request->request->get('_token', ''))) {
                $errors['csrf'] = 'Invalid CSRF token.';
            } else {
                // The shared service owns validation, the racy-duplicate catch, the atomic audit,
                // and password-lifecycle bookkeeping (passwordChangedAt + history seed).
                $result = $userService->create([
                    'email'    => $request->request->get('email', ''),
                    'name'     => $request->request->get('name', ''),
                    'password' => $request->request->get('password', ''),
                    'role'     => $request->request->get('role', 'ROLE_USER'),
                    'status'   => $request->request->get('status', 'active'),
                ], $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0');

                if ($result->isSuccess()) {
                    $this->addFlash('success', 'User created successfully.');

                    return $this->redirectToRoute('app_admin_users');
                }

                $errors = $result->errors;
            }
        }

        return $this->render('admin/users/new.html.twig', [
            'errors' => $errors,
        ]);
    }
}
