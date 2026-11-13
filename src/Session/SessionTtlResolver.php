<?php

declare(strict_types=1);

namespace App\Session;

use App\Service\ConfigService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves how long the CURRENT session stays valid, in seconds (ADR-051).
 *
 * The session is the authoritative credential in the admin realm — there is no remember-me bearer
 * cookie — so "stay signed in" is expressed purely as session lifetime. Two DB-backed knobs decide it:
 *
 *  - `session.idle_lifetime_minutes` (default 180 = 3h): the baseline sliding idle window every
 *    session gets. Replaces PHP's 24-minute `session.gc_maxlifetime` default, which was far too
 *    aggressive for an admin panel.
 *  - `session.remember_me_lifetime_days` (default 21): used INSTEAD of the baseline for a session
 *    that carries the {@see self::LONG_SESSION_KEY} flag — set at admin login when "Remember me"
 *    was ticked.
 *
 * The value is applied as the PdoSessionHandler `ttl`, which re-stamps `sess_lifetime` on EVERY
 * request (PdoSessionHandler::updateTimestamp), so the window slides on activity for free — that is
 * the "pushed back on every hit of activity" behaviour, with no refresh listener of our own.
 *
 * Because this runs inside the session write, it is deliberately defensive: it memoises per request
 * (so ConfigService is queried at most once, not once per write) and never throws — any failure
 * falls back to the baseline default rather than breaking session persistence.
 */
final class SessionTtlResolver
{
    /** Session key marking a session whose owner ticked "Remember me". */
    public const LONG_SESSION_KEY = '_long_session';

    public const DEFAULT_IDLE_MINUTES = 180;
    public const DEFAULT_REMEMBER_ME_DAYS = 21;

    private ?int $memo = null;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly RequestStack $requestStack,
    ) {}

    /** TTL in seconds for the current session. */
    public function resolve(): int
    {
        return $this->memo ??= $this->compute();
    }

    /** Forget the memoised value — the flag changed mid-request (e.g. at login). */
    public function reset(): void
    {
        $this->memo = null;
    }

    private function compute(): int
    {
        try {
            if ($this->isLongSession()) {
                $days = $this->configService->getInt(
                    'session.remember_me_lifetime_days',
                    self::DEFAULT_REMEMBER_ME_DAYS,
                );

                return max(1, $days) * 86400;
            }

            $minutes = $this->configService->getInt(
                'session.idle_lifetime_minutes',
                self::DEFAULT_IDLE_MINUTES,
            );

            return max(1, $minutes) * 60;
        } catch (\Throwable) {
            // Never let a config/DB hiccup break session writes — fall back to the baseline.
            return self::DEFAULT_IDLE_MINUTES * 60;
        }
    }

    private function isLongSession(): bool
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();

        // Reading an already-started session's bag is an in-memory array read — safe to call from
        // inside the handler's write. Never START a session here (that would recurse).
        if (!$session->isStarted()) {
            return false;
        }

        return $session->get(self::LONG_SESSION_KEY) === true;
    }
}
