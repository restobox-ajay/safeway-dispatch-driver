<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\LoginHistory;
use App\Entity\User;
use App\Security\LoginFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
class LoginHistoryListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private readonly LoginFingerprint $fingerprint,
    ) {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        ['ip' => $ip, 'userAgent' => $userAgent, 'fingerprint' => $fingerprint]
            = $this->fingerprint->contextFromRequest($event->getRequest());

        $history = new LoginHistory();
        $history->setUserId((int) $user->getId());
        $history->setIp($ip);
        $history->setUserAgent($userAgent);
        $history->setFingerprint($fingerprint);

        $this->em->persist($history);
        $this->em->flush();
    }
}
