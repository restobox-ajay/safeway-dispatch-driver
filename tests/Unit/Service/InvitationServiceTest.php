<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Invitation;
use App\Exception\InvitationAlreadyUsedException;
use App\Service\ConfigService;
use App\Service\InvitationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Unit test for the single InvitationService (FEATURE-103 AC2): the one mint-token,
 * email-link, and used-ness-guard site shared by the web and API admin invitation flows.
 */
final class InvitationServiceTest extends TestCase
{
    private function config(int $expiryDays = 7): ConfigService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('getInt')
            ->with('invitation.expiry_days', 7)
            ->willReturn($expiryDays);

        return $config;
    }

    public function testSendPersistsInvitationAndEmailsRecipient(): void
    {
        $persisted = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (object $inv) use (&$persisted): bool {
                $persisted = $inv;
                return $inv instanceof Invitation;
            }));
        $em->expects($this->once())->method('flush');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'app_register',
                $this->callback(fn (array $params): bool =>
                    isset($params['token']) && \is_string($params['token']) && $params['token'] !== ''),
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.com/register?token=plaintext');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                'admin/email/invitation_email.html.twig',
                $this->callback(fn (array $ctx): bool =>
                    ($ctx['registerUrl'] ?? null) === 'https://example.com/register?token=plaintext'
                    && ($ctx['expiresAt'] ?? null) instanceof \DateTimeImmutable),
            )
            ->willReturn('<p>invite</p>');

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) use (&$sent): bool {
                $sent = $email;
                return true;
            }));

        $service = new InvitationService($em, $mailer, $twig, $urlGenerator, $this->config());
        $returned = $service->send('invitee@example.com');

        // Invitation: bound to the email, stored only as a sha256 hash, unused, future expiry.
        $this->assertInstanceOf(Invitation::class, $persisted);
        $this->assertSame($returned, $persisted, 'send() returns the persisted invitation.');
        $this->assertSame('invitee@example.com', $persisted->getEmail());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $persisted->getTokenHash());
        $this->assertFalse($persisted->isUsed());
        $this->assertFalse($persisted->isExpired());

        // Email: addressed to the recipient with the invite subject and rendered body.
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('invitee@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('You have been invited to join', $sent->getSubject());
        $this->assertSame('<p>invite</p>', $sent->getHtmlBody());
    }

    public function testResendUsedInvitationThrowsAndSendsNothing(): void
    {
        $invitation = new Invitation('used@example.com', hash('sha256', 'original'), new \DateTimeImmutable('-1 hour'));
        $invitation->markUsed();
        $originalHash = $invitation->getTokenHash();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->never())->method('generate');

        $service = new InvitationService($em, $mailer, $twig, $urlGenerator, $this->config());

        try {
            $service->resend($invitation);
            $this->fail('Expected InvitationAlreadyUsedException for a used invitation.');
        } catch (InvitationAlreadyUsedException) {
            // expected
        }

        $this->assertSame($originalHash, $invitation->getTokenHash(), 'A used invitation must not be regenerated.');
    }

    public function testResendUnusedInvitationRegeneratesTokenAndEmails(): void
    {
        $invitation = new Invitation('resend@example.com', hash('sha256', 'original'), new \DateTimeImmutable('-1 hour'));
        $originalHash = $invitation->getTokenHash();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('app_register', $this->anything(), UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.com/register?token=renewed');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('admin/email/invitation_email.html.twig', $this->anything())
            ->willReturn('<p>renewed</p>');

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) use (&$sent): bool {
                $sent = $email;
                return true;
            }));

        $service = new InvitationService($em, $mailer, $twig, $urlGenerator, $this->config());
        $service->resend($invitation);

        // Token rotated, still unused, still bound to the same email.
        $this->assertNotSame($originalHash, $invitation->getTokenHash(), 'Token hash must change on resend.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->getTokenHash());
        $this->assertFalse($invitation->isUsed());

        // Email: addressed to the invitation's own recipient with the renewal subject.
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('resend@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('Your invitation has been renewed', $sent->getSubject());
    }
}
