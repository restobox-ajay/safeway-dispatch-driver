<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminPassword\AdminPasswordChange;
use App\AdminPassword\AdminPasswordGate;
use App\AdminPassword\PasswordChangeOutcome;
use App\Entity\Admin;
use App\Enum\AccountStatus;
use App\Repository\AdminRepository;
use App\Repository\AdminSessionRepository;
use App\Repository\DbConsoleSessionRepository;
use App\Security\AdminChecker;
use App\Security\PasswordPolicyManagerInterface;
use App\Security\AdminApiTokenManager;
use App\Security\RecoveryTokenInvalidator;
use App\Security\TechSupportVisibility;
use App\Service\AdminPasswordResetService;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/superadmin/admins')]
class AdminAdminManagementController extends AbstractController
{
    public function __construct(private readonly TechSupportVisibility $visibility)
    {
    }

    /**
     * The authenticated Admin driving this request. The class-level ROLE_SUPER_ADMIN guard
     * plus the admin firewall guarantee getUser() is an Admin here.
     */
    private function viewer(): Admin
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof Admin) {
            throw $this->createAccessDeniedException();
        }

        return $viewer;
    }

    /**
     * Fetch a target admin, treating one the viewer may not see exactly like a missing id
     * (ADR-050): a hidden tech-support account must not be enumerable by probing ids, so
     * both cases throw the identical 404.
     */
    private function findVisibleAdminOr404(AdminRepository $adminRepository, int $id): Admin
    {
        $admin = $adminRepository->find($id);
        if ($admin === null || !$this->visibility->canSee($this->viewer(), $admin)) {
            throw $this->createNotFoundException('Admin not found.');
        }

        return $admin;
    }

    /**
     * Roles the current viewer may assign. ROLE_TECH_SUPPORT is assignable only by
     * tech-support admins; to anyone else it does not exist, so a submitted value falls
     * back to ROLE_ADMIN exactly like any other unknown string (no error that would
     * reveal the role).
     */
    private function assignableRoles(): array
    {
        return $this->visibility->isTechSupport($this->viewer())
            ? Admin::ALLOWED_ROLES
            : array_values(array_diff(Admin::ALLOWED_ROLES, ['ROLE_TECH_SUPPORT']));
    }

    #[Route('', name: 'app_admin_superadmin_admins', methods: ['GET'])]
    public function list(AdminRepository $adminRepository, ConfigService $config): Response
    {
        // Tech-support accounts are filtered out for non-tech-support viewers (ADR-050).
        $admins = $this->visibility->filterVisible($this->viewer(), $adminRepository->findAll());

        return $this->render('admin/superadmin/admins.html.twig', [
            'admins' => $admins,
            // "Allow Impersonation" toggle (issue #9); the impersonate button is hidden when off.
            'impersonation_enabled' => $config->getBool('impersonate.enabled', true),
        ]);
    }

    #[Route('/new', name: 'app_admin_superadmin_admins_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        AdminRepository $adminRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        PasswordPolicyManagerInterface $passwordPolicy,
        AuditLogger $auditLogger,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_admin_create', (string) $request->request->get('_token', ''))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email    = trim((string) $request->request->get('email', ''));
            $name     = trim((string) $request->request->get('name', ''));
            $password = (string) $request->request->get('password', '');
            $role     = (string) $request->request->get('role', 'ROLE_ADMIN');

            if ($email === '') {
                $errors['email'] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif ($adminRepository->findByEmail($email) !== null) {
                $errors['email'] = 'An admin with this email already exists.';
            }

            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }

            if ($password === '') {
                $errors['password'] = 'Password is required.';
            } elseif (($policyErrors = $passwordPolicy->validate($password)) !== []) {
                $errors['password'] = $policyErrors[0];
            }

            if (!in_array($role, $this->assignableRoles(), true)) {
                $role = 'ROLE_ADMIN';
            }

            if ($errors === []) {
                $admin = new Admin();
                $admin->setEmail($email);
                $admin->setName($name);
                $admin->setPassword($passwordHasher->hashPassword($admin, $password));
                $admin->setRoles([$role]);
                $admin->setStatus('active');
                $em->persist($admin);

                // The admin INSERT and its 'success' audit row commit atomically (C10/AC2).
                $em->wrapInTransaction(function () use ($em, $auditLogger, $request, $email): void {
                    $auditLogger->logDeferred(
                        $this->getUser()?->getUserIdentifier() ?? 'unknown',
                        'admin',
                        $request->getClientIp() ?? '0.0.0.0',
                        'admin.admin_create',
                        'success',
                        $email
                    );
                });

                $this->addFlash('success', 'Admin created.');
                return $this->redirectToRoute('app_admin_superadmin_admins');
            }
        }

        return $this->render('admin/superadmin/admin_new.html.twig', ['errors' => $errors]);
    }

    #[Route('/{id}/edit', name: 'app_admin_superadmin_admins_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
        RecoveryTokenInvalidator $recoveryTokenInvalidator,
        AdminSessionRepository $adminSessionRepository,
        AdminApiTokenManager $apiTokens,
        DbConsoleSessionRepository $consoles,
    ): Response {
        $admin = $this->findVisibleAdminOr404($adminRepository, $id);

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_admin_edit_' . $id, (string) $request->request->get('_token', ''))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email  = trim((string) $request->request->get('email', ''));
            $name   = trim((string) $request->request->get('name', ''));
            $role   = (string) $request->request->get('role', 'ROLE_ADMIN');
            $status = (string) $request->request->get('status', 'active');

            if ($email === '') {
                $errors['email'] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif ($email !== $admin->getEmail()) {
                $existing = $adminRepository->findByEmail($email);
                if ($existing !== null && $existing->getId() !== $admin->getId()) {
                    $errors['email'] = 'An admin with this email already exists.';
                }
            }

            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }

            if (!in_array($role, $this->assignableRoles(), true)) {
                $role = 'ROLE_ADMIN';
            }
            if (!AccountStatus::isValid($status)) {
                $status = 'active';
            }

            // Anti-lockout guards: never let the panel strip the last active superadmin's
            // powers, and never let a superadmin deactivate their own account.
            $currentId    = $this->getUser() instanceof Admin ? $this->getUser()->getId() : 0;
            $wasSuper     = in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
            $losesPower   = $admin->isActive() && $wasSuper && ($role !== 'ROLE_SUPER_ADMIN' || $status !== 'active');

            if ($errors === [] && $losesPower && $adminRepository->countActiveSuperAdmins() <= 1) {
                $errors['role'] = 'This is the last active superadmin — its superadmin role cannot be removed and it cannot be deactivated.';
            }
            if ($errors === [] && $admin->getId() === $currentId && $status !== 'active') {
                $errors['status'] = 'You cannot deactivate your own account.';
            }

            if ($errors === []) {
                $previousEmail = $admin->getEmail();
                $admin->setEmail($email);
                $admin->setName($name);
                $admin->setRoles([$role]);
                $admin->setStatus($status);

                // The update and its 'success' audit row commit atomically (C10/AC2).
                $em->wrapInTransaction(function () use ($em, $auditLogger, $request, $admin): void {
                    $auditLogger->logDeferred(
                        $this->getUser()?->getUserIdentifier() ?? 'unknown',
                        'admin',
                        $request->getClientIp() ?? '0.0.0.0',
                        'admin.admin_edit',
                        'success',
                        $admin->getEmail()
                    );
                });

                // Deactivating an admin kills its outstanding recovery tokens (FEATURE-102) and tears
                // down its live admin_sessions so it stops showing as a ghost "active session" and is
                // logged out on its next request (FEATURE-148 / ADR-049 — mirrors the user realm).
                // Its admin API tokens are REVOKED, not merely rejected while inactive (issue #39 / ADR-064):
                // otherwise reactivating the account would bring every token ever issued back to life.
                if ($status === 'inactive') {
                    $recoveryTokenInvalidator->invalidateForAdmin($admin->getEmail());
                    $adminSessionRepository->deleteAllByAdminId((int) $admin->getId());
                    $apiTokens->revokeAllFor($admin, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0', 'deactivated');
                    $consoles->deleteAllByAdminId((int) $admin->getId());   // and any open DB console (issue #48)
                }

                // Reset links are resolved by email when used: an email change frees the old address, so kill the
                // links still pending for it before another account can be given that address (issue #23).
                if ($previousEmail !== $admin->getEmail()) {
                    $recoveryTokenInvalidator->invalidateForAdmin($previousEmail);
                }

                $this->addFlash('success', 'Admin updated.');
                return $this->redirectToRoute('app_admin_superadmin_admins');
            }
        }

        return $this->render('admin/superadmin/admin_edit.html.twig', [
            'admin'  => $admin,
            'errors' => $errors,
        ]);
    }

    /**
     * Tech support sets another admin's password directly (the emailed reset link stays available to
     * superadmins). Tech-support only: superadmins get 403 even though they reach the rest of this controller.
     * Permission, validation, write, audit and revocation all live in AdminPasswordGate (ADR-073).
     */
    #[IsGranted('ROLE_TECH_SUPPORT')]
    #[Route('/{id}/password', name: 'app_admin_superadmin_admins_set_password', methods: ['GET', 'POST'])]
    public function setPassword(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        AdminPasswordGate $passwordGate,
    ): Response {
        $admin = $this->findVisibleAdminOr404($adminRepository, $id);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_admin_set_password_' . $id, (string) $request->request->get('_token', ''))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $result = $passwordGate->change(AdminPasswordChange::bySupport(
                $this->viewer(),
                $admin,
                (string) $request->request->get('new_password', ''),
                (string) $request->request->get('confirm_password', ''),
                $request->getClientIp() ?? '0.0.0.0',
            ));

            if ($result->outcome === PasswordChangeOutcome::Forbidden) {
                throw $this->createAccessDeniedException();
            }
            if ($result->isChanged()) {
                $this->addFlash('success', 'Password changed for ' . $admin->getEmail() . '. Their sessions were closed.');

                return $this->redirectToRoute('app_admin_superadmin_admins');
            }
            $error = $result->message;
        }

        return $this->render('admin/superadmin/admin_password.html.twig', ['admin' => $admin, 'error' => $error]);
    }

    #[Route('/{id}/delete', name: 'app_admin_superadmin_admins_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
        RecoveryTokenInvalidator $recoveryTokenInvalidator,
        AdminSessionRepository $adminSessionRepository,
        AdminApiTokenManager $apiTokens,
        DbConsoleSessionRepository $consoles,
    ): Response {
        $admin = $this->findVisibleAdminOr404($adminRepository, $id);

        if (!$this->isCsrfTokenValid('admin_admin_delete_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentId = $this->getUser() instanceof Admin ? $this->getUser()->getId() : 0;
        if ($admin->getId() === $currentId) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }
        if ($admin->isActive()
            && in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true)
            && $adminRepository->countActiveSuperAdmins() <= 1
        ) {
            $this->addFlash('error', 'You cannot delete the last active superadmin.');
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }

        $deletedEmail = $admin->getEmail();

        // Soft delete (ADR-020 / FEATURE-110): admins are disabled, not physically removed —
        // status='inactive' means AdminChecker rejects them on the admin firewall while the row
        // and its audit trail stay intact. The last-active-superadmin guard above uses
        // isActive(), so a soft-deleted superadmin correctly stops counting as active.
        $admin->setStatus('inactive');

        // Soft-delete + its 'success' audit row commit atomically (C10/AC2).
        $em->wrapInTransaction(function () use ($em, $auditLogger, $request, $deletedEmail): void {
            $auditLogger->logDeferred(
                $this->getUser()?->getUserIdentifier() ?? 'unknown',
                'admin',
                $request->getClientIp() ?? '0.0.0.0',
                'admin.admin_delete',
                'success',
                $deletedEmail
            );
        });

        // Soft-delete disables the admin; kill its outstanding recovery tokens too (FEATURE-102) and
        // tear down its live admin_sessions rows so they stop lingering as ghost "active sessions" and
        // the admin is logged out on its next request (FEATURE-148 / ADR-049 — mirrors the user realm).
        $recoveryTokenInvalidator->invalidateForAdmin($deletedEmail);
        $adminSessionRepository->deleteAllByAdminId((int) $admin->getId());
        // ...and revokes its admin API tokens for good (issue #39 / ADR-064).
        $apiTokens->revokeAllFor($admin, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->getClientIp() ?? '0.0.0.0', 'deleted');
        $consoles->deleteAllByAdminId((int) $admin->getId());   // and any open DB console (issue #48)

        $this->addFlash('success', 'Admin deleted.');
        return $this->redirectToRoute('app_admin_superadmin_admins');
    }

    #[Route('/{id}/reset-password', name: 'app_admin_superadmin_admins_reset_password', methods: ['POST'])]
    public function resetPassword(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        AdminPasswordResetService $resetService,
        AuditLogger $auditLogger,
    ): Response {
        $admin = $this->findVisibleAdminOr404($adminRepository, $id);

        if (!$this->isCsrfTokenValid('admin_admin_reset_password_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $resetService->sendResetLink($admin);

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.admin_password_reset',
            'success',
            $admin->getEmail()
        );

        $this->addFlash('success', 'A password reset email has been sent to ' . $admin->getEmail() . '.');
        return $this->redirectToRoute('app_admin_superadmin_admins');
    }

    #[Route('/{id}/reset-2fa', name: 'app_admin_superadmin_admins_reset_2fa', methods: ['POST'])]
    public function resetTwoFactor(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        $admin = $this->findVisibleAdminOr404($adminRepository, $id);

        if (!$this->isCsrfTokenValid('admin_admin_reset_2fa_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$admin->isTotpEnabled()) {
            $this->addFlash('info', '2FA is not enabled for ' . $admin->getEmail() . '.');
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }

        $admin->setTotpSecret(null);
        $admin->setIsTotpEnabled(false);
        $admin->setLastTotpCounter(null);
        $em->flush();

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.admin_reset_2fa',
            'success',
            $admin->getEmail()
        );

        $this->addFlash('success', '2FA has been reset for ' . $admin->getEmail() . '.');
        return $this->redirectToRoute('app_admin_superadmin_admins');
    }

    #[Route('/{id}/impersonate', name: 'app_admin_superadmin_impersonate', methods: ['POST'])]
    public function impersonateStart(
        int $id,
        Request $request,
        AdminRepository $adminRepository,
        TokenStorageInterface $tokenStorage,
        AuditLogger $auditLogger,
        AdminChecker $adminChecker,
        ConfigService $config,
    ): Response {
        $targetAdmin = $this->findVisibleAdminOr404($adminRepository, $id);

        if (!$this->isCsrfTokenValid('admin_impersonate_admin_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // "Allow Impersonation" (issue #9) covers superadmins impersonating admins too.
        if (!$config->getBool('impersonate.enabled', true)) {
            $this->addFlash('error', 'Impersonation is disabled (Config → Impersonation).');
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }

        // Gate impersonation on the SAME check a real admin login runs (review C23 / FEATURE-133):
        // hand-forging a PostAuthenticationToken skips the admin firewall's user_checker, so an
        // inactive (or otherwise non-loginable) admin could otherwise be impersonated into a live
        // session. Reject fail-closed — no token set, no impersonation markers, no audit row.
        try {
            $adminChecker->checkPreAuth($targetAdmin);
        } catch (AuthenticationException) {
            $this->addFlash('error', 'This admin cannot be impersonated (the account is not active).');
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }

        $superadminEmail = $this->getUser()?->getUserIdentifier() ?? 'unknown';
        $session         = $request->getSession();

        // Record who is impersonating whom. The original superadmin is restored on exit
        // by loading the Admin via the admin user provider from _impersonating_admin_by
        // (a plain identifier) — NOT from a serialized token blob (review C18 / FEATURE-134).
        $session->set('_impersonating_admin_as', $targetAdmin->getEmail());
        $session->set('_impersonating_admin_by', $superadminEmail);

        // Create the target admin's security token and set it on TokenStorage. The admin
        // firewall is stateful, so ContextListener.onKernelResponse serializes this token
        // into _security_admin at response time — no manual (un)serialize in app code.
        $tokenStorage->setToken(new PostAuthenticationToken($targetAdmin, 'admin', $targetAdmin->getRoles()));

        $auditLogger->log(
            $superadminEmail,
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.impersonate_admin_start',
            'success',
            $targetAdmin->getEmail()
        );

        return $this->redirectToRoute('app_admin_dashboard');
    }
}
