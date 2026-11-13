<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invitation;
use App\Exception\InvitationAlreadyUsedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Single site for minting an invitation token (256-bit, stored only as a SHA-256 hash) and
 * emailing the recipient a link to `app_register`. Shared by the web (AdminInvitationController)
 * and API (AdminApiInvitationController) admin invitation flows so the mint/email logic — and the
 * used-ness resend guard — cannot drift across the two surfaces (FEATURE-103 AC2 / review C8).
 *
 * Realm-specific concerns (CSRF, audit, flash vs JSON, 404 lookup) stay in the callers.
 */
final class InvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ConfigService $configService,
    ) {}

    public function send(string $email): Invitation
    {
        [$plaintext, $tokenHash, $expiresAt] = $this->mintToken();

        $invitation = new Invitation($email, $tokenHash, $expiresAt);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->emailLink($email, $plaintext, $expiresAt, 'You have been invited to join');

        return $invitation;
    }

    /**
     * Rotate an existing invitation's token and re-send the link. The ONLY guard — shared by both
     * surfaces — is used-ness: a used invitation has already created an account, and regenerating it
     * (which nulls usedAt) would allow a second account. Expiry does NOT block resend; rotating a
     * valid-but-leaked invite is a supported operation (ADR-022 / FEATURE-101).
     *
     * @throws InvitationAlreadyUsedException if the invitation has already been consumed.
     */
    public function resend(Invitation $invitation): void
    {
        if ($invitation->isUsed()) {
            throw new InvitationAlreadyUsedException();
        }

        [$plaintext, $tokenHash, $expiresAt] = $this->mintToken();

        $invitation->regenerate($tokenHash, $expiresAt);
        $this->em->flush();

        $this->emailLink($invitation->getEmail(), $plaintext, $expiresAt, 'Your invitation has been renewed');
    }

    /**
     * @return array{0: string, 1: string, 2: \DateTimeImmutable} plaintext token, its sha256 hash, expiry
     */
    private function mintToken(): array
    {
        $expiryDays = $this->configService->getInt('invitation.expiry_days', 7);
        $plaintext  = bin2hex(random_bytes(32));

        return [$plaintext, hash('sha256', $plaintext), new \DateTimeImmutable("+{$expiryDays} days")];
    }

    private function emailLink(string $to, string $plaintext, \DateTimeImmutable $expiresAt, string $subject): void
    {
        $registerUrl = $this->urlGenerator->generate(
            'app_register',
            ['token' => $plaintext],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new Email())
            ->to($to)
            ->subject($subject)
            ->html($this->twig->render('admin/email/invitation_email.html.twig', [
                'registerUrl' => $registerUrl,
                'expiresAt'   => $expiresAt,
            ]));

        $this->mailer->send($email);
    }
}
