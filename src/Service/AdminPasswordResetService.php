<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Admin;
use App\Entity\AdminPasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Mints a single-use, 1-hour admin reset token (256-bit, stored only as a SHA-256 hash)
 * and emails the admin a link to `app_admin_reset_password`. Shared by the self-service
 * `/admin/forgot-password` flow and the superadmin "reset this admin" panel action.
 */
final class AdminPasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendResetLink(Admin $admin): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new AdminPasswordResetToken(
            $admin->getEmail(),
            hash('sha256', $plaintext),
            new \DateTimeImmutable('+1 hour'),
        );
        $this->em->persist($token);
        $this->em->flush();

        $resetUrl = $this->urlGenerator->generate(
            'app_admin_reset_password',
            ['token' => $plaintext],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new Email())
            ->to($admin->getEmail())
            ->subject('Reset your admin password')
            ->html($this->twig->render('admin/security/email/reset_password_email.html.twig', [
                'resetUrl'  => $resetUrl,
                'expiresAt' => $token->getExpiresAt(),
            ]));

        $this->mailer->send($email);
    }
}
