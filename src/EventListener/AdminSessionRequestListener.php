<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Repository\AdminSessionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * FEATURE-123: admin-side mirror of {@see UserSessionRequestListener}. On each interactive admin request
 * it (1) enforces "logout everywhere" — if the admin's admin_sessions row is gone (terminated on another
 * device / via terminate-all) the session is invalidated and the token cleared — and (2) bumps
 * last_active_at on the live row.
 *
 * Skips the stateless admin-api firewall (^/admin-api): a PAT request carries an Admin token too, so the
 * instanceof check alone would not distinguish it — the path guard does (FEATURE-097 / AC4). PAT auth
 * neither creates nor requires an admin_sessions row.
 */
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -10)]
class AdminSessionRequestListener
{
    /** @see UserSessionRequestListener::LAST_ACTIVE_THROTTLE_SECONDS — same throttle window. */
    private const LAST_ACTIVE_THROTTLE_SECONDS = 60;

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AdminSessionRepository $adminSessionRepository,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Skip the stateless admin-api firewall — no session-based tracking for PAT auth (AC4).
        if (str_starts_with($event->getRequest()->getPathInfo(), '/admin-api')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $admin = $token->getUser();
        if (!$admin instanceof Admin) {
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
        $adminSession = $this->adminSessionRepository->findBySessionId($sessionId);

        if ($adminSession === null) {
            // Session was terminated — log the admin out.
            $request->getSession()->invalidate();
            $this->tokenStorage->setToken(null);
            return;
        }

        // Throttled scoped one-row UPDATE of last_active_at — never a whole-unit-of-work
        // $em->flush() (review C10 / FEATURE-106, AC5).
        $now = new \DateTimeImmutable();
        if ($now->getTimestamp() - $adminSession->getLastActiveAt()->getTimestamp() >= self::LAST_ACTIVE_THROTTLE_SECONDS) {
            $this->adminSessionRepository->touchLastActive($sessionId, $now);
        }
    }
}
