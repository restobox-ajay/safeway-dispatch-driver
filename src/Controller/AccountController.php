<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LoginHistoryRepository;
use App\Repository\UserSessionRepository;
use App\Security\PasswordPolicyManagerInterface;
use App\Security\TwoFactorChallengeGuardInterface;
use App\Security\UserTwoFactorManagerInterface;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/account')]
class AccountController extends AbstractController
{
    #[Route('/login-history', name: 'app_account_login_history', methods: ['GET'])]
    public function loginHistory(LoginHistoryRepository $loginHistoryRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $history = $loginHistoryRepo->findRecentByUserId((int) $user->getId());

        return $this->render('account/login_history.html.twig', [
            'history' => $history,
        ]);
    }

    #[Route('/sessions', name: 'app_account_sessions', methods: ['GET'])]
    public function sessions(Request $request, UserSessionRepository $sessionRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessions = $sessionRepo->findByUserId((int) $user->getId());
        $currentSessionId = $request->getSession()->getId();

        return $this->render('account/sessions.html.twig', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId,
        ]);
    }

    #[Route('/sessions/terminate-all', name: 'app_account_sessions_terminate_all', methods: ['POST'])]
    public function terminateAllSessions(Request $request, UserSessionRepository $sessionRepo, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('session_terminate_all', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_account_sessions');
        }

        /** @var User $user */
        $user = $this->getUser();
        $sessionRepo->deleteAllByUserId((int) $user->getId());

        // Deleting user_sessions rows alone does not stop a remember-me cookie from
        // re-authenticating (its HMAC binds only identifier/expiry/password). Bump the
        // sessions-invalidated marker (folded into the remember-me HMAC) so every outstanding
        // remember-me cookie for this user is invalidated too.
        $user->setSessionsInvalidatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'All sessions terminated.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/sessions/{id}/terminate', name: 'app_account_sessions_terminate', methods: ['POST'])]
    public function terminateSession(int $id, Request $request, UserSessionRepository $sessionRepo, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('session_terminate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_account_sessions');
        }

        /** @var User $user */
        $user = $this->getUser();
        $userSession = $sessionRepo->find($id);

        if ($userSession === null || $userSession->getUserId() !== (int) $user->getId()) {
            throw $this->createNotFoundException('Session not found.');
        }

        $em->remove($userSession);
        $em->flush();

        $this->addFlash('success', 'Session terminated.');
        return $this->redirectToRoute('app_account_sessions');
    }

    #[Route('/change-expired-password', name: 'app_account_change_expired_password', methods: ['GET', 'POST'])]
    public function changeExpiredPassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        PasswordPolicyManagerInterface $passwordPolicy,
        TwoFactorChallengeGuardInterface $twoFactorGuard,
        AuditLogger $auditLogger,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // This route is deliberately skipped by TwoFactorChallengeListener, so a session that
        // has not yet cleared its TOTP challenge would otherwise be able to rotate the password
        // here. Enforce the challenge in-controller before anything else.
        if ($twoFactorGuard->isChallengePending($request, $user)) {
            return $this->redirectToRoute('app_2fa_challenge');
        }

        // The forced-change page exists only for a genuinely expired password. A user whose
        // password is not expired belongs on the normal, current-password-gated change form.
        if (!$passwordPolicy->isExpired($user)) {
            return $this->redirectToRoute('app_account_change_password');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change_expired_password', $request->request->get('_token'))) {
                $error = 'Invalid CSRF token.';
            } else {
                $currentPassword = (string) $request->request->get('current_password', '');
                $newPassword = (string) $request->request->get('password', '');
                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $error = 'Your current password is incorrect.';
                } elseif ($newPassword === '') {
                    $error = 'Password is required.';
                } else {
                    $policyErrors = $passwordPolicy->validate($newPassword);
                    if ($policyErrors !== []) {
                        $error = implode(' ', $policyErrors);
                    } else {
                        $error = $passwordPolicy->checkReuse($user, $newPassword);
                    }
                }

                if ($error === null) {
                    $hashed = $passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashed);
                    $em->flush();
                    $passwordPolicy->recordPasswordChange($user, $user->getPassword());
                    $auditLogger->log($user->getEmail(), 'user', $request->getClientIp() ?? '0.0.0.0', 'password_change', 'success', 'expired');

                    $this->addFlash('success', 'Your password has been updated. Please log in again.');
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('account/change_expired_password.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/password', name: 'app_account_change_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        PasswordPolicyManagerInterface $passwordPolicy,
        AuditLogger $auditLogger,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change_password', $request->request->get('_token'))) {
                $error = 'Invalid CSRF token.';
            } else {
                $current = (string) $request->request->get('current_password', '');
                $new     = (string) $request->request->get('new_password', '');
                $confirm = (string) $request->request->get('confirm_password', '');

                if (!$passwordHasher->isPasswordValid($user, $current)) {
                    $error = 'Your current password is incorrect.';
                } elseif ($new === '') {
                    $error = 'A new password is required.';
                } elseif ($new !== $confirm) {
                    $error = 'The new passwords do not match.';
                } elseif (($policyErrors = $passwordPolicy->validate($new)) !== []) {
                    $error = implode(' ', $policyErrors);
                } elseif (($reuse = $passwordPolicy->checkReuse($user, $new)) !== null) {
                    $error = $reuse;
                }

                if ($error === null) {
                    $user->setPassword($passwordHasher->hashPassword($user, $new));
                    $em->flush();
                    $passwordPolicy->recordPasswordChange($user, $user->getPassword());
                    $auditLogger->log($user->getEmail(), 'user', $request->getClientIp() ?? '0.0.0.0', 'password_change', 'success', 'self-service');

                    // Changing the password invalidates the current session (User::isEqualTo
                    // compares the hash) and every remember-me cookie (HMAC bound to the hash),
                    // so the user must sign in again with the new password.
                    $this->addFlash('success', 'Your password has been changed. Please log in again.');
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('account/change_password.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/settings', name: 'app_account_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserTwoFactorManagerInterface $twoFactor): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_settings', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_account_settings');
            }

            $enabled = $request->request->get('login_notifications_enabled') === '1';
            $user->setLoginNotificationsEnabled($enabled);
            $em->flush();

            $this->addFlash('success', 'Settings saved.');
            return $this->redirectToRoute('app_account_settings');
        }

        return $this->render('account/settings.html.twig', [
            'user' => $user,
            // Resolved via the core port: real value when auth-2fa-bundle is registered, always false
            // (null-object) when it is absent. The template's 2FA block is additionally gated on the
            // bundle-set `two_factor_available` Twig global so its bundle-route links never render when
            // the feature is uninstalled.
            'two_factor_enabled' => $twoFactor->isEnabled($user),
        ]);
    }
}
