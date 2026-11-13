<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Entity\AdminLoginHistory;
use App\Security\LoginFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * FEATURE-109: admin-side mirror of {@see LoginHistoryListener}. Records each interactive admin login
 * into the dedicated admin_login_history table, following ADR-003 realm isolation.
 *
 * Kept deliberately separate and explicit from the user listener (review C8 / AC4): the only code
 * shared across realms is the pure {@see LoginFingerprint} helper. There is no abstract base listener
 * and no cross-realm store interface — this listener writes its own admin table inline. Only
 * interactive, session-backed logins are recorded — never stateless admin-api (PAT) auth (FEATURE-097).
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
class AdminLoginHistoryListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoginFingerprint $fingerprint,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $admin = $event->getUser();
        if (!$admin instanceof Admin) {
            return;
        }

        ['ip' => $ip, 'userAgent' => $userAgent, 'fingerprint' => $fingerprint]
            = $this->fingerprint->contextFromRequest($event->getRequest());

        $history = new AdminLoginHistory();
        $history->setAdminId((int) $admin->getId());
        $history->setIp($ip);
        $history->setUserAgent($userAgent);
        $history->setFingerprint($fingerprint);

        $this->em->persist($history);
        $this->em->flush();
    }
}
