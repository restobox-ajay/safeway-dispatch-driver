<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Security\PasswordPolicyManagerInterface;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use App\Service\PasswordResetService;
use App\Service\WebhookDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        PasswordResetService $passwordResetService,
        AuditLogger $auditLogger,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        if ($request->isMethod('POST')) {
            $ip = $request->getClientIp() ?? '0.0.0.0';
            // Throttle ABOVE the CSRF check (ADR-016) so a missing token can't bypass it.
            if ($rateLimiter->tooManyAttempts('forgot_password', $ip)) {
                return $this->render('security/forgot_password.html.twig', [
                    'error' => 'Too many requests. Please try again later.',
                ]);
            }

            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token', ''))) {
                return $this->render('security/forgot_password.html.twig', [
                    'error' => 'Invalid security token. Please try again.',
                ]);
            }

            $email = (string) $request->request->get('email', '');

            $user = $userRepository->findByEmail($email);

            if ($user !== null) {
                $passwordResetService->sendResetLink($user);

                $auditLogger->log(
                    $email,
                    'user',
                    $request->getClientIp() ?? '0.0.0.0',
                    'password_reset_request',
                    'success'
                );
            }

            // Always redirect to the same confirmation page (no enumeration)
            return $this->redirectToRoute('app_forgot_password_check');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/forgot-password/check', name: 'app_forgot_password_check', methods: ['GET'])]
    public function check(): Response
    {
        return $this->render('security/forgot_password_check.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        PasswordPolicyManagerInterface $passwordPolicy,
        PasswordResetTokenRepository $resetTokenRepository,
        UserSessionRepository $userSessionRepository,
        ConfigService $configService,
        WebhookDispatcherInterface $webhookDispatcher,
        AuditLogger $auditLogger,
    ): Response {
        $tokenHash = hash('sha256', $token);
        $resetToken = $em->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $tokenHash]);

        if ($resetToken === null || !$resetToken->isValid()) {
            return $this->render('security/reset_password.html.twig', [
                'error' => $resetToken?->isExpired()
                    ? 'This password reset link has expired. Please request a new one.'
                    : 'This password reset link is invalid or has already been used.',
                'token' => null,
            ]);
        }

        // Reject consumption for a deactivated / soft-deleted account (FEATURE-102 / ADR-020):
        // even a token issued before deactivation cannot reset the password of an inactive account.
        // Covers both the GET form and the POST submit. Deactivation also proactively kills tokens.
        $account = $userRepository->findByEmail($resetToken->getEmail());
        if ($account === null || $account->getStatus() !== 'active') {
            return $this->render('security/reset_password.html.twig', [
                'error' => 'This password reset link is no longer valid.',
                'token' => null,
            ]);
        }

        if ($request->isMethod('POST')) {
            $newPassword = (string) $request->request->get('password', '');

            if ($newPassword === '') {
                $policyErrors = ['Password is required.'];
            } else {
                $policyErrors = $passwordPolicy->validate($newPassword);
            }

            if ($policyErrors !== []) {
                return $this->render('security/reset_password.html.twig', [
                    'error' => implode(' ', $policyErrors),
                    'token' => $token,
                ]);
            }

            $user = $userRepository->findByEmail($resetToken->getEmail());

            if ($user === null) {
                return $this->render('security/reset_password.html.twig', [
                    'error' => 'User account not found.',
                    'token' => null,
                ]);
            }

            $reuseError = $passwordPolicy->checkReuse($user, $newPassword);
            if ($reuseError !== null) {
                return $this->render('security/reset_password.html.twig', [
                    'error' => $reuseError,
                    'token' => $token,
                ]);
            }

            $hashed = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashed);
            $resetToken->markUsed();
            $em->flush();

            $passwordPolicy->recordPasswordChange($user, $user->getPassword());

            // Invalidate any other still-unused reset tokens for this email so a
            // sibling link from an earlier request can no longer be used.
            $resetTokenRepository->invalidateOtherUnusedTokens($resetToken->getEmail(), $resetToken->getId());

            // Invalidate the user's active sessions ("logout everywhere", FEATURE-011):
            // dropping the cross-reference rows causes UserSessionRequestListener to
            // tear down each orphaned session on its next request. Remember-me cookies
            // are invalidated implicitly — ConfigAwareRememberMeHandler binds its HMAC
            // to the password hash, which has just changed.
            $userSessionRepository->deleteAllByUserId((int) $user->getId());

            // The credential change itself is audited, not only the request (issue #18 / SPEC: password reset).
            $auditLogger->log($user->getEmail(), 'user', $request->getClientIp() ?? '0.0.0.0', 'password_reset', 'success');

            $webhookUrl = $configService->getString('webhook.password_reset_url', '')
                ?: $configService->getString('webhook.global_url', '');
            if ($webhookUrl !== '') {
                $webhookDispatcher->dispatch($webhookUrl, [
                    'event_type' => 'password_reset',
                    'actor'      => $resetToken->getEmail(),
                    'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'ip'         => $request->getClientIp() ?? '0.0.0.0',
                ]);
            }

            $this->addFlash('success', 'Your password has been reset. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'error' => null,
            'token' => $token,
        ]);
    }
}
