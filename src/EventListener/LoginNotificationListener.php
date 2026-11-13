<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\LoginNotificationSeenRepository;
use App\Security\LoginFingerprint;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use App\Service\LoginNotificationChecker;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Twig\Environment;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess', priority: 10)]
class LoginNotificationListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private readonly LoginNotificationSeenRepository $seenRepository,
        private readonly LoginNotificationChecker $checker,
        private readonly LoginFingerprint $fingerprint,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly ConfigService $configService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$this->checker->shouldNotifyUser($user)) {
            return;
        }

        ['ip' => $ip, 'userAgent' => $userAgent, 'fingerprint' => $fingerprint]
            = $this->fingerprint->contextFromRequest($event->getRequest());

        $mode = $this->configService->getString('login_notifications.recognition_mode', 'fingerprint');

        // Recognition is driven by the dedicated marker store, NOT login_history — so the decision
        // is independent of whether LoginHistoryListener has already run this request (no
        // listener-priority coupling). The marker is computed per recognition_mode.
        $marker = $mode === 'ip_only' ? $ip : $fingerprint;

        $userId = (int) $user->getId();
        if ($this->seenRepository->hasSeen($userId, $marker)) {
            return;
        }

        $loggedAt = new \DateTimeImmutable();
        $html = $this->twig->render('security/email/login_notification_email.html.twig', [
            'ip'        => $ip,
            'userAgent' => $userAgent,
            'loggedAt'  => $loggedAt,
        ]);

        $email = (new Email())
            ->to($user->getUserIdentifier())
            ->subject('New login to your account')
            ->html($html);

        $this->mailer->send($email);

        // Record recognition memory so a subsequent login from the same device is not re-alerted.
        $this->seenRepository->markSeen($userId, $marker);

        // Visibility-only audit trail. This row is NOT read back for recognition (that stays the
        // marker store), so audit retention/pruning can never cause a spurious re-alert.
        $this->auditLogger->log(
            $user->getUserIdentifier(),
            'user',
            $ip,
            'login_notification_sent',
            'success',
        );
    }
}
