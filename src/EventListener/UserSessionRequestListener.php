<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserSessionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -10)]
class UserSessionRequestListener
{
    /**
     * Minimum seconds between last_active_at writes for the same session. The activity timestamp
     * only needs coarse granularity, so we skip the write on most requests — important on SQLite,
     * where every write takes a whole-DB lock (review C10 / FEATURE-106, AC5).
     */
    private const LAST_ACTIVE_THROTTLE_SECONDS = 60;

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UserSessionRepository $userSessionRepository,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Skip stateless API firewall requests — no session-based user session tracking for PAT auth
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        // Skip impersonation routes — the authenticator's onAuthenticationSuccess() return stops
        // event propagation anyway, but guard explicitly to be safe
        if (str_starts_with($event->getRequest()->getPathInfo(), '/impersonate')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $sessionId = $request->getSession()->getId();
        if ($sessionId === '') {
            return;
        }

        // Session-existence check runs on EVERY request so terminated-session logout is immediate.
        $userSession = $this->userSessionRepository->findBySessionId($sessionId);

        if ($userSession === null) {
            // Session was terminated — log the user out
            $request->getSession()->invalidate();
            $this->tokenStorage->setToken(null);
            return;
        }

        // Throttle the last_active_at bump: skip it unless the stored timestamp is stale, and when
        // it is, write it with a scoped one-row UPDATE — never $em->flush() (avoids a per-request
        // whole-unit-of-work write / collateral commit — C10/AC5).
        $now = new \DateTimeImmutable();
        if ($now->getTimestamp() - $userSession->getLastActiveAt()->getTimestamp() >= self::LAST_ACTIVE_THROTTLE_SECONDS) {
            $this->userSessionRepository->touchLastActive($sessionId, $now);
        }
    }
}
