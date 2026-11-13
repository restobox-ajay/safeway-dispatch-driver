<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminPassword\AdminPasswordChange;
use App\AdminPassword\AdminPasswordGate;
use App\Entity\Admin;
use App\Repository\AdminLoginHistoryRepository;
use App\Repository\AdminSessionRepository;
use App\Repository\DbConsoleSessionRepository;
use App\Security\EndpointRateLimiterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An admin's self-service account pages, scoped strictly to the logged-in admin (queried by
 * getUser()->getId()), so an admin can never see or act on another admin's data:
 *  - FEATURE-109: recent login history (the admin-side mirror of {@see AccountController::loginHistory}).
 *  - FEATURE-123: active sessions + "logout everywhere" (the admin-side mirror of
 *    {@see AccountController::sessions}), backed by the dedicated admin_sessions table.
 *  - "Change my password": collects the form; the change itself is AdminPasswordGate's (ADR-073).
 */
#[IsGranted('ROLE_ADMIN')]
class AdminAccountController extends AbstractController
{
    #[Route('/admin/password', name: 'app_admin_account_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, AdminPasswordGate $passwordGate, EndpointRateLimiterInterface $rateLimiter): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            // Throttle ABOVE the CSRF check, keyed to the account: this form verifies the current password,
            // so it must not be a guessing oracle for someone holding a hijacked session.
            if ($rateLimiter->tooManyAttempts('admin_change_password', 'admin:' . $admin->getId())) {
                $error = 'Too many attempts. Please try again later.';
            } elseif (!$this->isCsrfTokenValid('admin_change_password', (string) $request->request->get('_token', ''))) {
                $error = 'Invalid CSRF token.';
            } else {
                $result = $passwordGate->change(AdminPasswordChange::own(
                    $admin,
                    (string) $request->request->get('current_password', ''),
                    (string) $request->request->get('new_password', ''),
                    (string) $request->request->get('confirm_password', ''),
                    $request->getClientIp() ?? '0.0.0.0',
                ));

                if ($result->isChanged()) {
                    // The gate closed every session of this admin, this one included.
                    $this->addFlash('success', 'Your password has been changed. Please log in again.');

                    return $this->redirectToRoute('app_admin_login');
                }
                $error = $result->message ?? 'The password could not be changed.';
            }
        }

        return $this->render('admin/account/change_password.html.twig', ['error' => $error]);
    }

    #[Route('/admin/login-history', name: 'app_admin_login_history', methods: ['GET'])]
    public function loginHistory(AdminLoginHistoryRepository $loginHistoryRepo): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        $history = $loginHistoryRepo->findRecentByAdminId((int) $admin->getId());

        return $this->render('admin/account/login_history.html.twig', [
            'history' => $history,
        ]);
    }

    #[Route('/admin/sessions', name: 'app_admin_account_sessions', methods: ['GET'])]
    public function sessions(Request $request, AdminSessionRepository $sessionRepo): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();
        $sessions = $sessionRepo->findByAdminId((int) $admin->getId());
        $currentSessionId = $request->getSession()->getId();

        return $this->render('admin/account/sessions.html.twig', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId,
        ]);
    }

    #[Route('/admin/sessions/terminate-all', name: 'app_admin_account_sessions_terminate_all', methods: ['POST'])]
    public function terminateAllSessions(Request $request, AdminSessionRepository $sessionRepo, DbConsoleSessionRepository $consoles): Response
    {
        if (!$this->isCsrfTokenValid('admin_session_terminate_all', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_account_sessions');
        }

        /** @var Admin $admin */
        $admin = $this->getUser();

        // Delete every admin_sessions row for this admin. The admin firewall has no remember-me cookie
        // (unlike the user side), so there is no HMAC marker to bump — on the next request the
        // AdminSessionRequestListener finds no matching row and invalidates the session (terminated-session
        // logout), including the current one.
        $sessionRepo->deleteAllByAdminId((int) $admin->getId());
        // ...and any open DB console: "logout everywhere" includes the console (issue #48).
        $consoles->deleteAllByAdminId((int) $admin->getId());

        $this->addFlash('success', 'All sessions terminated.');
        return $this->redirectToRoute('app_admin_login');
    }

    #[Route('/admin/sessions/{id}/terminate', name: 'app_admin_account_sessions_terminate', methods: ['POST'])]
    public function terminateSession(int $id, Request $request, AdminSessionRepository $sessionRepo, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_session_terminate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_account_sessions');
        }

        /** @var Admin $admin */
        $admin = $this->getUser();
        $adminSession = $sessionRepo->find($id);

        if ($adminSession === null || $adminSession->getAdminId() !== (int) $admin->getId()) {
            throw $this->createNotFoundException('Session not found.');
        }

        $em->remove($adminSession);
        $em->flush();

        $this->addFlash('success', 'Session terminated.');
        return $this->redirectToRoute('app_admin_account_sessions');
    }
}
