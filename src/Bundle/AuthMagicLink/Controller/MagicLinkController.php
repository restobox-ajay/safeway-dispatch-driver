<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Controller;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MagicLinkController extends AbstractController
{
    #[Route('/magic-link', name: 'app_magic_link', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        ConfigService $configService,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        $errors = $request->getSession()->getFlashBag()->get('magic_link_error');
        $error = $errors[0] ?? null;

        if ($request->isMethod('POST')) {
            $ip = $request->getClientIp() ?? '0.0.0.0';
            if ($rateLimiter->tooManyAttempts('magic_link', $ip)) {
                return $this->render('security/magic_link.html.twig', [
                    'error' => 'Too many requests. Please try again later.',
                ]);
            }

            if (!$this->isCsrfTokenValid('magic_link_request', (string) $request->request->get('_token', ''))) {
                return $this->render('security/magic_link.html.twig', [
                    'error' => 'Invalid security token. Please try again.',
                ]);
            }

            $email = trim((string) $request->request->get('email', ''));
            $user = $userRepository->findByEmail($email);

            if ($user !== null && $user->getStatus() === 'active') {
                $expiryMinutes = $configService->getInt('magic_link.expiry_minutes', 15);
                $plaintextToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $plaintextToken);
                $expiresAt = new \DateTimeImmutable(sprintf('+%d minutes', $expiryMinutes));

                $magicToken = new MagicLinkToken($email, $tokenHash, $expiresAt);
                $em->persist($magicToken);
                $em->flush();

                $verifyUrl = $this->generateUrl(
                    'app_magic_link_verify',
                    ['token' => $plaintextToken],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $emailMessage = (new Email())
                    ->to($email)
                    ->subject('Your magic login link')
                    ->html($this->renderView('security/email/magic_link_email.html.twig', [
                        'verifyUrl' => $verifyUrl,
                        'expiresAt' => $expiresAt,
                        'expiryMinutes' => $expiryMinutes,
                    ]));

                $mailer->send($emailMessage);
            }

            // Always redirect to check page — no enumeration
            return $this->redirectToRoute('app_magic_link_check');
        }

        return $this->render('security/magic_link.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/magic-link/check', name: 'app_magic_link_check', methods: ['GET'])]
    public function check(): Response
    {
        return $this->render('security/magic_link_check.html.twig');
    }

    /**
     * Route target for MagicLinkAuthenticator. MagicLinkAuthenticator runs first:
     * - If token is valid: authenticator succeeds, returns null, and this action runs → redirect to dashboard
     * - If token is invalid: authenticator calls onAuthenticationFailure → redirect to /magic-link
     */
    #[Route('/magic-link/verify', name: 'app_magic_link_verify', methods: ['GET'])]
    public function verify(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }
}
