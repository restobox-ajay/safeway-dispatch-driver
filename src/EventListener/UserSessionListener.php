<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
#[AsEventListener(event: LogoutEvent::class, method: 'onLogout')]
class UserSessionListener
{
    use InteractiveFirewallTrait;

    public function __construct(private EntityManagerInterface $em) {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $sessionId = $request->getSession()->getId();
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $userAgent = substr($request->headers->get('User-Agent', ''), 0, 512);

        // Remove any stale row with the same session_id before inserting.
        // Flush the removal immediately so DELETE happens before INSERT — Doctrine's UoW
        // processes inserts before deletes, which would cause a unique constraint violation
        // if we deferred both operations to a single flush().
        $existing = $this->em->getRepository(UserSession::class)->findOneBy(['sessionId' => $sessionId]);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $userSession = new UserSession();
        $userSession->setSessionId($sessionId);
        $userSession->setUserId((int) $user->getId());
        $userSession->setIp($ip);
        $userSession->setUserAgent($userAgent);

        $this->em->persist($userSession);
        $this->em->flush();
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $sessionId = $event->getRequest()->getSession()->getId();
        $userSession = $this->em->getRepository(UserSession::class)->findOneBy(['sessionId' => $sessionId]);
        if ($userSession !== null) {
            $this->em->remove($userSession);
            $this->em->flush();
        }
    }
}
