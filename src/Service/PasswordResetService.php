<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Mints a single-use, 1-hour user reset token (256-bit, stored only as a SHA-256 hash)
 * and emails the user a link to `app_reset_password`. This is the single site shared by
 * the self-service `/forgot-password` flow and the admin "reset this user" web/API actions
 * (FEATURE-103 AC3). Mirrors AdminPasswordResetService for the user realm; the two remain
 * distinct services so the realm boundary (ADR-003) is preserved.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendResetLink(User $user): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new PasswordResetToken(
            $user->getEmail(),
            hash('sha256', $plaintext),
            new \DateTimeImmutable('+1 hour'),
        );
        $this->em->persist($token);
        $this->em->flush();

        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $plaintext],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Reset your password')
            ->html($this->twig->render('security/email/reset_password_email.html.twig', [
                'resetUrl'  => $resetUrl,
                'expiresAt' => $token->getExpiresAt(),
            ]));

        $this->mailer->send($email);
    }
}
