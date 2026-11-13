<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Controller;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\Auth2fa\Security\TrustedDeviceManager;
use App\Entity\User;
use App\Security\EndpointRateLimiterInterface;
use App\Security\SafeRedirect;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use App\Service\TotpService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Moved into auth-2fa-bundle (FEATURE-143 / ADR-043). The user TOTP enrolment/challenge flow. All
 * enrolment state reads/writes go through {@see TwoFactorSettingsRepository} (the satellite table),
 * never through User columns. TotpService stays in core (shared with the admin realm).
 */
#[IsGranted('ROLE_USER')]
class TwoFactorController extends AbstractController
{
    #[Route('/account/2fa/setup', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, TotpService $totp, TwoFactorSettingsRepository $settings, AuditLogger $auditLogger): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();
        $error = null;
        $alreadyEnabled = $settings->isEnabled($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('2fa_setup', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_2fa_setup');
            }

            $secret = $session->get('_2fa_temp_secret');
            $code = trim((string) $request->request->get('_code', ''));

            if ($secret === null) {
                $this->addFlash('error', 'Setup session expired. Please try again.');
                return $this->redirectToRoute('app_2fa_setup');
            }

            $matchedCounter = $totp->verifyCode($secret, $code);
            if ($matchedCounter !== null) {
                // Persist the enrolment and seed the replay floor so the enabling code can't be
                // replayed at login.
                $settings->enable($user, $secret, $matchedCounter);

                $session->remove('_2fa_temp_secret');
                $session->set('_2fa_verified', $user->getId());
                $auditLogger->log($user->getEmail(), 'user', $request->getClientIp() ?? '0.0.0.0', '2fa_enable', 'success', $alreadyEnabled ? 'reconfigured' : 'enrolled');

                $this->addFlash('success', 'Two-factor authentication has been enabled.');
                return $this->redirectToRoute('app_account_settings');
            }

            $error = 'Invalid code. Please try again.';
            $secret = $session->get('_2fa_temp_secret');
        } elseif ($alreadyEnabled && $request->query->get('reconfigure') !== '1') {
            // Already enrolled: don't silently mint a fresh secret/QR. Show the status
            // panel (disable / explicit reconfigure) instead. Reconfigure is deliberate
            // via ?reconfigure=1.
            return $this->render('account/2fa/setup.html.twig', [
                'alreadyEnabled' => true,
                'reconfigure'    => false,
                'secret'         => null,
                'qrCodeDataUri'  => null,
                'error'          => null,
            ]);
        } else {
            $secret = $totp->generateSecret();
            $session->set('_2fa_temp_secret', $secret);
        }

        $qrCodeDataUri = $totp->getQrCodeDataUri($secret, $user->getEmail());

        return $this->render('account/2fa/setup.html.twig', [
            'alreadyEnabled' => false,
            // True only when an already-enrolled user is deliberately re-enrolling — used to
            // warn that confirming the new code replaces their current authenticator.
            'reconfigure'    => $alreadyEnabled,
            'secret'         => $secret,
            'qrCodeDataUri'  => $qrCodeDataUri,
            'error'          => $error,
        ]);
    }

    #[Route('/account/2fa/disable', name: 'app_2fa_disable', methods: ['POST'])]
    public function disable(Request $request, TwoFactorSettingsRepository $settings, AuditLogger $auditLogger): Response
    {
        if (!$this->isCsrfTokenValid('2fa_disable', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_account_settings');
        }

        /** @var User $user */
        $user = $this->getUser();
        $settings->disable($user);

        $request->getSession()->remove('_2fa_verified');
        $auditLogger->log($user->getEmail(), 'user', $request->getClientIp() ?? '0.0.0.0', '2fa_disable', 'success');

        $this->addFlash('success', 'Two-factor authentication has been disabled.');
        return $this->redirectToRoute('app_account_settings');
    }

    #[Route('/2fa/challenge', name: 'app_2fa_challenge', methods: ['GET', 'POST'])]
    public function challenge(
        Request $request,
        TotpService $totp,
        TrustedDeviceManager $trustedDeviceManager,
        ConfigService $configService,
        TwoFactorSettingsRepository $settings,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();
        $error = null;

        if ($request->isMethod('POST')) {
            // Keyed by account: brute-forcing the TOTP code targets a specific user.
            if ($rateLimiter->tooManyAttempts('2fa_challenge', (string) $user->getId())) {
                $trustedDeviceDays = $configService->getInt('trusted_device.lifetime_days', 30);

                return $this->render('security/2fa/challenge.html.twig', [
                    'error' => 'Too many attempts. Please try again later.',
                    'trusted_device_days' => $trustedDeviceDays,
                ]);
            }

            if (!$this->isCsrfTokenValid('2fa_challenge', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_2fa_challenge');
            }

            $code = trim((string) $request->request->get('_code', ''));
            $secret = $settings->getSecret($user);

            $matchedCounter = $secret !== null
                ? $totp->verifyCode($secret, $code, $settings->getLastCounter($user) ?? PHP_INT_MIN)
                : null;

            if ($matchedCounter !== null) {
                // Consume this counter so the same code can't be replayed within its window.
                $settings->recordCounter($user, $matchedCounter);
                // The id, not `true`: the marker is only honoured for the user who passed (TwoFactorGuard).
                $session->set('_2fa_verified', $user->getId());

                // Only ever redirect to a same-origin LOCAL path — a spoofed Host or a
                // crafted request URI must not be able to turn this into an off-origin
                // redirect (FEATURE-131 / review C17).
                $targetUrl = SafeRedirect::localPathOr($session->get('_2fa_target_url'), '/dashboard');
                $session->remove('_2fa_target_url');

                $response = $this->redirect($targetUrl);

                if ($request->request->get('_trust_device') === '1') {
                    $lifetimeDays = $configService->getInt('trusted_device.lifetime_days', 30);
                    $cookie = $trustedDeviceManager->generateCookie($user, $secret, $lifetimeDays, $request->isSecure());
                    $response->headers->setCookie($cookie);
                }

                return $response;
            }

            $error = 'Invalid authentication code. Please try again.';
        }

        $trustedDeviceDays = $configService->getInt('trusted_device.lifetime_days', 30);

        return $this->render('security/2fa/challenge.html.twig', [
            'error' => $error,
            'trusted_device_days' => $trustedDeviceDays,
        ]);
    }
}
