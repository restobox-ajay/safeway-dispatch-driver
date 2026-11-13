<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Repository\AdminLoginNotificationSeenRepository;
use App\Security\LoginFingerprint;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Twig\Environment;

/**
 * FEATURE-108: admin-side mirror of {@see LoginNotificationListener}. Emails an admin when they log in
 * from an unrecognized device, following ADR-003 realm isolation and the explicit-separation preference.
 *
 * Kept deliberately separate and explicit from the user listener (review C8 / AC5): the only code shared
 * across realms is the pure {@see LoginFingerprint} helper and the string-param {@see AuditLogger} sink.
 * There is no abstract base listener and no cross-realm store interface — this listener writes its own
 * dedicated admin_login_notification_seen table inline. Admin has no per-account notification toggle
 * (unlike User.loginNotificationsEnabled), so enablement is the global admin config key only.
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess', priority: 10)]
class AdminLoginNotificationListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private readonly AdminLoginNotificationSeenRepository $seenRepository,
        private readonly LoginFingerprint $fingerprint,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly ConfigService $configService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Only interactive, session-backed logins — never stateless admin-api (PAT) auth (FEATURE-097).
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $admin = $event->getUser();
        if (!$admin instanceof Admin) {
            return;
        }

        // Explicit admin-specific enable switch (AC10), independent of the user key. Defaults ON.
        if (!$this->configService->getBool('login_notifications.admin_enabled', true)) {
            return;
        }

        ['ip' => $ip, 'userAgent' => $userAgent, 'fingerprint' => $fingerprint]
            = $this->fingerprint->contextFromRequest($event->getRequest());

        $mode = $this->configService->getString('login_notifications.admin_recognition_mode', 'fingerprint');

        // Recognition is driven by the dedicated admin marker store, NOT login history or the audit log
        // — so the decision is independent of any listener ordering. The marker is computed per mode.
        $marker = $mode === 'ip_only' ? $ip : $fingerprint;

        $adminId = (int) $admin->getId();
        if ($this->seenRepository->hasSeen($adminId, $marker)) {
            return;
        }

        $loggedAt = new \DateTimeImmutable();
        $html = $this->twig->render('security/email/login_notification_email.html.twig', [
            'ip'        => $ip,
            'userAgent' => $userAgent,
            'loggedAt'  => $loggedAt,
        ]);

        $email = (new Email())
            ->to($admin->getUserIdentifier())
            ->subject('New login to your admin account')
            ->html($html);

        $this->mailer->send($email);

        // Record recognition memory so a subsequent login from the same device is not re-alerted.
        $this->seenRepository->markSeen($adminId, $marker);

        // Visibility-only audit trail (actorType 'admin'). This row is NOT read back for recognition
        // (that stays the marker store), so audit retention/pruning can never cause a spurious re-alert.
        $this->auditLogger->log(
            $admin->getUserIdentifier(),
            'admin',
            $ip,
            'login_notification_sent',
            'success',
        );
    }
}
