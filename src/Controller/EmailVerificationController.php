<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerificationController extends AbstractController
{
    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyUserEmail(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        VerifyEmailHelperInterface $verifyEmailHelper,
    ): Response {
        $userId = $request->query->get('id');

        if (!$userId) {
            $this->addFlash('error', 'Invalid verification link.');
            return $this->redirectToRoute('app_login');
        }

        $user = $userRepository->find((int) $userId);

        if (!$user) {
            $this->addFlash('error', 'Invalid verification link.');
            return $this->redirectToRoute('app_login');
        }

        try {
            $verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                (string) $user->getId(),
                $user->getEmail()
            );
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', $exception->getReason());
            return $this->redirectToRoute('app_login');
        }

        $user->setIsVerified(true);
        $em->flush();

        $this->addFlash('success', 'Your email address has been verified. You can now log in.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/resend-verification', name: 'app_resend_verification', methods: ['GET'])]
    public function resendForm(): Response
    {
        return $this->render('security/resend_verification.html.twig');
    }

    #[Route('/resend-verification', name: 'app_resend_verification_post', methods: ['POST'])]
    public function resendVerification(
        Request $request,
        UserRepository $userRepository,
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer,
        ConfigService $configService,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        $ip = $request->getClientIp() ?? '0.0.0.0';
        if ($rateLimiter->tooManyAttempts('resend_verification', $ip)) {
            $this->addFlash('error', 'Too many requests. Please try again later.');
            return $this->redirectToRoute('app_resend_verification');
        }

        if (!$this->isCsrfTokenValid('resend_verification', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_resend_verification');
        }

        $email = trim((string) $request->request->get('email', ''));
        $user  = $email !== '' ? $userRepository->findByEmail($email) : null;

        $mode = $configService->getString('email_verification.mode', 'disabled');

        if ($user !== null && !$user->isVerified() && $mode !== 'disabled') {
            $signatureComponents = $verifyEmailHelper->generateSignature(
                'app_verify_email',
                (string) $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()]
            );

            $verificationEmail = (new Email())
                ->to($user->getEmail())
                ->subject('Please verify your email address')
                ->html($this->renderView('security/email/verification_email.html.twig', [
                    'signedUrl' => $signatureComponents->getSignedUrl(),
                    'expiresAt' => $signatureComponents->getExpiresAt(),
                ]));

            $mailer->send($verificationEmail);
        }

        $this->addFlash('success', 'If your email is registered and unverified, a new verification email has been sent.');

        return $this->redirectToRoute('app_login');
    }
}
