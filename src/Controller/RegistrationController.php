<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InvitationAlreadyUsedException;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Security\EndpointRateLimiterInterface;
use App\Security\PasswordPolicyManagerInterface;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use App\Service\WebhookDispatcherInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        ConfigService $configService,
        AuditLogger $auditLogger,
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer,
        InvitationRepository $invitationRepository,
        PasswordPolicyManagerInterface $passwordPolicy,
        WebhookDispatcherInterface $webhookDispatcher,
        EndpointRateLimiterInterface $rateLimiter,
    ): Response {
        $mode = $configService->getString('registration.mode', 'open');

        $invitation      = null;
        $invitationEmail = '';
        $inviteToken     = '';

        if ($mode === 'invitation-only') {
            // Token may arrive via query string (GET) or hidden field (POST re-render)
            $inviteToken = (string) ($request->query->get('token') ?? $request->request->get('_invite_token', ''));

            if ($inviteToken === '') {
                return new Response('Registration is by invitation only.', Response::HTTP_FORBIDDEN);
            }

            $tokenHash  = hash('sha256', $inviteToken);
            $invitation = $invitationRepository->findByTokenHash($tokenHash);

            if ($invitation === null || $invitation->isUsed()) {
                return new Response('Invalid invitation token.', Response::HTTP_FORBIDDEN);
            }

            if ($invitation->isExpired()) {
                return $this->render('registration/register.html.twig', [
                    'errors'          => ['token' => 'This invitation link has expired. Please ask the admin to resend your invitation.'],
                    'invitationEmail' => '',
                    'inviteToken'     => '',
                ]);
            }

            $invitationEmail = $invitation->getEmail();
        }

        $errors = [];

        $renderForm = fn (array $errors): Response => $this->render('registration/register.html.twig', [
            'errors'          => $errors,
            'invitationEmail' => $invitationEmail,
            'inviteToken'     => $inviteToken,
        ]);

        if ($request->isMethod('POST')) {
            // Throttle ABOVE the CSRF check (ADR-016) so a missing token cannot bypass it.
            $ip = $request->getClientIp() ?? '0.0.0.0';
            if ($rateLimiter->tooManyAttempts('register', $ip)) {
                return $renderForm(['rate_limit' => 'Too many requests. Please try again later.']);
            }

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token', ''))) {
                return $renderForm(['csrf' => 'Invalid security token. Please try again.']);
            }

            $email    = trim((string) $request->request->get('email', ''));
            $name     = trim((string) $request->request->get('name', ''));
            $password = (string) $request->request->get('password', '');

            if ($email === '') {
                $errors['email'] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif ($invitation !== null
                && strtolower($email) !== strtolower($invitation->getEmail())) {
                // Bind the account to the invited address — an invite link may only be used
                // to register the exact email it was issued for.
                $errors['email'] = 'This email address does not match the invitation.';
            } elseif ($userRepository->findByEmail($email) !== null) {
                $errors['email'] = 'This email address is already registered.';
            }

            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }

            if ($password === '') {
                $errors['password'] = 'Password is required.';
            } else {
                $policyErrors = $passwordPolicy->validate($password);
                if ($policyErrors !== []) {
                    $errors['password'] = implode(' ', $policyErrors);
                }
            }

            if ($errors === []) {
                $user = new User();
                $user->setEmail($email);
                $user->setName($name);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setStatus('active');

                // Claim the invite and create the account in ONE transaction so two
                // concurrent registrations cannot both pass the earlier isUsed() check:
                // the atomic claim (used_at NULL -> now) affects one row for the winner and
                // zero for the loser, and the DB's user.email UNIQUE index rejects a
                // duplicate email. Either race rolls the whole thing back — no half-created
                // account, no double-consumed invite (FEATURE-119 / review C27, ADR-030).
                try {
                    $em->wrapInTransaction(function () use ($em, $user, $invitation, $invitationRepository): void {
                        if ($invitation !== null && !$invitationRepository->claim((int) $invitation->getId())) {
                            throw new InvitationAlreadyUsedException();
                        }

                        $em->persist($user);
                        $em->flush();
                    });
                } catch (InvitationAlreadyUsedException) {
                    return $renderForm(['token' => 'This invitation has already been used.']);
                } catch (UniqueConstraintViolationException) {
                    return $renderForm(['email' => 'This email address is already registered.']);
                }

                $passwordPolicy->recordPasswordChange($user, $user->getPassword());

                $auditLogger->log(
                    $email,
                    'user',
                    $request->getClientIp() ?? '0.0.0.0',
                    'register',
                    'success'
                );

                $webhookUrl = $configService->getString('webhook.registration_url', '')
                    ?: $configService->getString('webhook.global_url', '');
                if ($webhookUrl !== '') {
                    $webhookDispatcher->dispatch($webhookUrl, [
                        'event_type' => 'registration',
                        'actor'      => $email,
                        'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        'ip'         => $request->getClientIp() ?? '0.0.0.0',
                    ]);
                }

                $verificationMode = $configService->getString('email_verification.mode', 'disabled');
                if ($verificationMode !== 'disabled') {
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
                    $this->addFlash('success', 'Registration successful. Please check your email to verify your address.');
                } else {
                    $this->addFlash('success', 'Registration successful. You can now log in.');
                }

                return $this->redirectToRoute('app_login');
            }
        }

        return $renderForm($errors);
    }
}
