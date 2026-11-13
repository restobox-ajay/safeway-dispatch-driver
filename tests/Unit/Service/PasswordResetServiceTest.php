<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Unit test for the user-side PasswordResetService (FEATURE-103 AC3): the single
 * mint-token-and-email site that the self-service and admin/API reset flows share.
 */
final class PasswordResetServiceTest extends TestCase
{
    public function testSendResetLinkPersistsTokenAndEmailsUser(): void
    {
        $user = new User();
        $user->setEmail('target@example.com');

        $persisted = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (object $token) use (&$persisted): bool {
                $persisted = $token;
                return $token instanceof PasswordResetToken;
            }));
        $em->expects($this->once())->method('flush');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'app_reset_password',
                $this->callback(fn (array $params): bool =>
                    isset($params['token']) && \is_string($params['token']) && $params['token'] !== ''),
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.com/reset-password/plaintext');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                'security/email/reset_password_email.html.twig',
                $this->callback(fn (array $ctx): bool =>
                    ($ctx['resetUrl'] ?? null) === 'https://example.com/reset-password/plaintext'
                    && ($ctx['expiresAt'] ?? null) instanceof \DateTimeImmutable),
            )
            ->willReturn('<p>reset</p>');

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) use (&$sent): bool {
                $sent = $email;
                return true;
            }));

        $service = new PasswordResetService($em, $mailer, $twig, $urlGenerator);
        $service->sendResetLink($user);

        // Token: bound to the user's email, stored only as a sha256 hash, ~1h expiry, unused.
        $this->assertInstanceOf(PasswordResetToken::class, $persisted);
        $this->assertSame('target@example.com', $persisted->getEmail());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $persisted->getTokenHash());
        $this->assertFalse($persisted->isUsed());
        $this->assertFalse($persisted->isExpired());
        $delta = $persisted->getExpiresAt()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        $this->assertGreaterThan(3000, $delta);
        $this->assertLessThanOrEqual(3600, $delta);

        // Email: addressed to the user with the standard reset subject and rendered body.
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('target@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('Reset your password', $sent->getSubject());
        $this->assertSame('<p>reset</p>', $sent->getHtmlBody());
    }
}
