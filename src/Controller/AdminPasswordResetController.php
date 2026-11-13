<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminPassword\AdminPasswordChange;
use App\AdminPassword\AdminPasswordGate;
use App\AdminPassword\PasswordChangeOutcome;
use App\Repository\AdminRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Service\AdminPasswordResetService;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service password reset for Admin accounts (ADR-009), on the `admin` firewall.
 * Mirrors the hardened user reset: rate-limited (above CSRF), CSRF-protected, anti-
 * enumeration (always the same confirmation), single-use 1-hour hashed tokens, sibling
 * tokens invalidated on success. The password change itself is AdminPasswordGate's (ADR-073);
 * this controller only sends the link (request) and collects the new password (reset).
 */
class AdminPasswordResetController extends AbstractController
{
    #[Route('/admin/forgot-password', name: 'app_admin_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        AdminRepository $adminRepository,
        AdminPasswordResetService $resetService,
        AuditLogger $auditLogger,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        if ($request->isMethod('POST')) {
            $ip = $request->getClientIp() ?? '0.0.0.0';
            // Throttle ABOVE the CSRF check so a missing token can't bypass it.
            if ($rateLimiter->tooManyAttempts('admin_forgot_password', $ip)) {
                return $this->render('admin/security/forgot_password.html.twig', [
                    'error' => 'Too many requests. Please try again later.',
                ]);
            }

            if (!$this->isCsrfTokenValid('admin_forgot_password', (string) $request->request->get('_token', ''))) {
                return $this->render('admin/security/forgot_password.html.twig', [
                    'error' => 'Invalid security token. Please try again.',
                ]);
            }

            $email = (string) $request->request->get('email', '');
            $admin = $adminRepository->findByEmail($email);

            if ($admin !== null) {
                $resetService->sendResetLink($admin);
                $auditLogger->log($email, 'admin', $ip, 'admin.password_reset_request', 'success');
            }

            // Always the same confirmation, regardless of whether the admin exists.
            return $this->redirectToRoute('app_admin_forgot_password_check');
        }

        return $this->render('admin/security/forgot_password.html.twig');
    }

    #[Route('/admin/forgot-password/check', name: 'app_admin_forgot_password_check', methods: ['GET'])]
    public function check(): Response
    {
        return $this->render('admin/security/forgot_password_check.html.twig');
    }

    #[Route('/admin/reset-password/{token}', name: 'app_admin_reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, AdminPasswordGate $passwordGate): Response
    {
        // The same rules the gate applies on submit, so a dead link is refused up front (GET and POST).
        if (($problem = $passwordGate->resetLinkProblem($token)) !== null) {
            return $this->render('admin/security/reset_password.html.twig', ['error' => $problem, 'token' => null]);
        }

        if ($request->isMethod('POST')) {
            $result = $passwordGate->change(AdminPasswordChange::viaResetLink(
                $token,
                (string) $request->request->get('password', ''),
                $request->getClientIp() ?? '0.0.0.0',
            ));

            if ($result->isChanged()) {
                $this->addFlash('success', 'Your password has been reset. You can now log in.');

                return $this->redirectToRoute('app_admin_login');
            }

            return $this->render('admin/security/reset_password.html.twig', [
                'error' => $result->message,
                'token' => $result->outcome === PasswordChangeOutcome::Rejected ? $token : null,
            ]);
        }

        return $this->render('admin/security/reset_password.html.twig', ['error' => null, 'token' => $token]);
    }
}
