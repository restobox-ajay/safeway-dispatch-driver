<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Admin;
use App\Security\EndpointRateLimiterInterface;
use App\Security\SafeRedirect;
use App\Service\AuditLogger;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-realm TOTP two-factor: enrol, disable, and the login challenge (FEATURE-126). The
 * admin-side mirror of {@see TwoFactorController}, reusing the pure {@see TotpService}. Session
 * state is realm-scoped (`_admin_2fa_*`) so it can never be confused with the user flow, which
 * shares the same PHP session (ADR-014).
 */
#[IsGranted('ROLE_ADMIN')]
class AdminTwoFactorController extends AbstractController
{
    #[Route('/admin/2fa/setup', name: 'app_admin_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, TotpService $totp, EntityManagerInterface $em, AuditLogger $auditLogger): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();
        $session = $request->getSession();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_2fa_setup', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_2fa_setup');
            }

            $secret = $session->get('_admin_2fa_temp_secret');
            $code = trim((string) $request->request->get('_code', ''));

            if ($secret === null) {
                $this->addFlash('error', 'Setup session expired. Please try again.');
                return $this->redirectToRoute('app_admin_2fa_setup');
            }

            $matchedCounter = $totp->verifyCode($secret, $code);
            if ($matchedCounter !== null) {
                $wasEnabled = $admin->isTotpEnabled();
                $admin->setTotpSecret($secret);
                $admin->setIsTotpEnabled(true);
                // Seed the replay floor so the enabling code can't be replayed at login.
                $admin->setLastTotpCounter($matchedCounter);
                $em->flush();

                $session->remove('_admin_2fa_temp_secret');
                $session->set('_admin_2fa_verified', $admin->getId());
                $auditLogger->log($admin->getEmail(), 'admin', $request->getClientIp() ?? '0.0.0.0', 'admin.2fa_enable', 'success', $wasEnabled ? 'reconfigured' : 'enrolled');

                $this->addFlash('success', 'Two-factor authentication has been enabled.');
                return $this->redirectToRoute('app_admin_dashboard');
            }

            $error = 'Invalid code. Please try again.';
            $secret = $session->get('_admin_2fa_temp_secret');
        } elseif ($admin->isTotpEnabled() && $request->query->get('reconfigure') !== '1') {
            // Already enrolled: show the status panel (disable / explicit reconfigure) rather than
            // silently minting a fresh secret. Reconfigure is deliberate via ?reconfigure=1.
            return $this->render('admin/2fa/setup.html.twig', [
                'alreadyEnabled' => true,
                'reconfigure'    => false,
                'secret'         => null,
                'qrCodeDataUri'  => null,
                'error'          => null,
            ]);
        } else {
            $secret = $totp->generateSecret();
            $session->set('_admin_2fa_temp_secret', $secret);
        }

        $qrCodeDataUri = $totp->getQrCodeDataUri($secret, $admin->getEmail());

        return $this->render('admin/2fa/setup.html.twig', [
            'alreadyEnabled' => false,
            'reconfigure'    => $admin->isTotpEnabled(),
            'secret'         => $secret,
            'qrCodeDataUri'  => $qrCodeDataUri,
            'error'          => $error,
        ]);
    }

    #[Route('/admin/2fa/disable', name: 'app_admin_2fa_disable', methods: ['POST'])]
    public function disable(Request $request, EntityManagerInterface $em, AuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('admin_2fa_disable', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_2fa_setup');
        }

        /** @var Admin $admin */
        $admin = $this->getUser();
        $admin->setTotpSecret(null);
        $admin->setIsTotpEnabled(false);
        $admin->setLastTotpCounter(null);
        $em->flush();

        $request->getSession()->remove('_admin_2fa_verified');
        $auditLogger->log($admin->getEmail(), 'admin', $request->getClientIp() ?? '0.0.0.0', 'admin.2fa_disable', 'success');

        $this->addFlash('success', 'Two-factor authentication has been disabled.');
        return $this->redirectToRoute('app_admin_2fa_setup');
    }

    #[Route('/admin/2fa/challenge', name: 'app_admin_2fa_challenge', methods: ['GET', 'POST'])]
    public function challenge(
        Request $request,
        TotpService $totp,
        EntityManagerInterface $em,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        /** @var Admin $admin */
        $admin = $this->getUser();
        $session = $request->getSession();
        $error = null;

        if ($request->isMethod('POST')) {
            // Keyed by account: brute-forcing the TOTP code targets a specific admin.
            if ($rateLimiter->tooManyAttempts('admin_2fa_challenge', (string) $admin->getId())) {
                return $this->render('admin/2fa/challenge.html.twig', [
                    'error' => 'Too many attempts. Please try again later.',
                ]);
            }

            if (!$this->isCsrfTokenValid('admin_2fa_challenge', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_2fa_challenge');
            }

            $code = trim((string) $request->request->get('_code', ''));
            $secret = $admin->getTotpSecret();

            $matchedCounter = $secret !== null
                ? $totp->verifyCode($secret, $code, $admin->getLastTotpCounter() ?? PHP_INT_MIN)
                : null;

            if ($matchedCounter !== null) {
                // Consume this counter so the same code can't be replayed within its window.
                $admin->setLastTotpCounter($matchedCounter);
                $em->flush();
                // The id, not `true`: the marker is only honoured for the admin who passed (AdminTwoFactorChallengeListener).
                $session->set('_admin_2fa_verified', $admin->getId());

                // Only ever redirect to a same-origin LOCAL path (mirrors FEATURE-131 / review C17).
                $targetUrl = SafeRedirect::localPathOr($session->get('_admin_2fa_target_url'), '/admin/dashboard');
                $session->remove('_admin_2fa_target_url');

                return $this->redirect($targetUrl);
            }

            $error = 'Invalid authentication code. Please try again.';
        }

        return $this->render('admin/2fa/challenge.html.twig', [
            'error' => $error,
        ]);
    }
}
