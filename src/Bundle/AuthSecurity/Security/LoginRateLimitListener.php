<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Security;

use App\Service\ConfigService;
use App\Service\WebhookDispatcherInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Login form throttling + hard account lockout. Moved into auth-security-bundle (FEATURE-144 / ADR-044):
 * it is registered — and therefore login throttling / lockout only happens — when the bundle is enabled.
 * The lockout it applies now lives in the bundle-owned `account_lockouts` satellite (keyed by user_id via
 * a DBAL upsert on email) rather than a `locked_until` column on `user`; core enforces it via the
 * {@see \App\Security\AccountLockManagerInterface} port (aliased to the bundle repository).
 */
final class LoginRateLimitListener
{
    /**
     * Fail-closed default applied when the admin has never configured a value.
     * An explicit configured value of 0 still disables throttling (ConfigService
     * returns this default only when the key is absent). Shared with
     * {@see EndpointRateLimiter} so login and the other auth endpoints behave alike.
     */
    public const DEFAULT_MAX_ATTEMPTS = EndpointRateLimiter::DEFAULT_MAX_ATTEMPTS;

    /** Request attribute: the login_attempts row this try was recorded as. */
    private const ATTEMPT_ID = '_login_attempt_id';

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigService $configService,
        private readonly RequestStack $requestStack,
        private readonly WebhookDispatcherInterface $webhookDispatcher,
    ) {}

    /**
     * Priority 2048: ahead of CsrfProtectionListener (512) and UserCheckerListener (256), like Symfony's own
     * LoginThrottlingListener. It reads only the UserBadge identifier and never loads the user, so a throttled
     * request is refused identically whether or not the account exists (issue #25 — at 200 it ran after the
     * user lookup, and an unknown email still got "Invalid credentials": an account-existence oracle).
     */
    #[AsEventListener(event: CheckPassportEvent::class, priority: 2048)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $maxAttempts = $this->configService->getInt('rate_limit.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        if ($maxAttempts <= 0) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $windowSeconds = $this->configService->getInt('rate_limit.window_seconds', 300);
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        // Scope every count by realm so admin-firewall failures never throttle user logins
        // (and vice versa) — the two firewalls are isolated (ADR-003 / ADR-021).
        $realm = $this->realmForRequest($request);
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $passport = $event->getPassport();
        // The same 254-char cut onLoginFailure() records (issue #22), so count and record always agree.
        $email = $passport->hasBadge(UserBadge::class)
            ? mb_substr($passport->getBadge(UserBadge::class)->getUserIdentifier(), 0, 254)
            : null;

        // This try is recorded BEFORE its password is checked and then counted with itself included (audit #13):
        // counting first and recording only after a failure let parallel posts all read "under the limit" and
        // each get a guess. The row becomes the failure record, or is removed when the login succeeds.
        $this->connection->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at, realm) VALUES (?, ?, ?, ?)',
            [$ip, $email, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $realm]
        );
        $attemptId = (int) $this->connection->lastInsertId();

        // Per-IP check
        $ipCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND ip = ? AND attempted_at > ?',
            [$realm, $ip, $cutoff]
        );
        // Per-account check
        $accountCount = $email === null ? 0 : (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND email = ? AND attempted_at > ?',
            [$realm, $email, $cutoff]
        );
        if ($ipCount > $maxAttempts || $accountCount > $maxAttempts) {
            // A refused try is not another failure (it never reached the password check).
            $this->connection->executeStatement('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
            throw new TooManyLoginAttemptsException();
        }
        $request->attributes->set(self::ATTEMPT_ID, $attemptId);
    }

    /** A successful login is not a failed attempt: its recorded try is removed. */
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTEMPT_ID);
        if (\is_int($id)) { // set only by onCheckPassport(), for the form login
            $this->connection->executeStatement('DELETE FROM login_attempts WHERE id = ?', [$id]);
        }
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getException() instanceof TooManyLoginAttemptsException) {
            return;
        }

        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $ip = $event->getRequest()->getClientIp() ?? '0.0.0.0';
        $email = $this->accountIdentifier($event);
        $realm = $this->realmForRequest($event->getRequest());
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Normally the try was already recorded by onCheckPassport(); record it here only when it was not
        // (a failure before the passport checks ran).
        if (!\is_int($event->getRequest()->attributes->get(self::ATTEMPT_ID))) {
            $this->connection->executeStatement(
                'INSERT INTO login_attempts (ip, email, attempted_at, realm) VALUES (?, ?, ?, ?)',
                [$ip, $email, $now, $realm]
            );
        }

        $pruneCutoff = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'DELETE FROM login_attempts WHERE attempted_at < ?',
            [$pruneCutoff]
        );

        $this->maybeApplyLockout($email, $realm);
    }

    /**
     * The account a failure counts against: exactly the identifier the firewall authenticated with — the
     * same value onCheckPassport() counts by — never the raw form field (issue #26). FormLoginAuthenticator
     * trim()s the username before loading the user, so 'victim@x.com ' checks the real victim's password; if
     * the raw padded string were recorded here, those failures would never count toward 'victim@x.com' and
     * the INSERT … SELECT lockout would match no user — unlimited per-account guessing from rotating IPs.
     * The fallback (no passport) applies the same trim() so the two can never disagree.
     */
    private function accountIdentifier(LoginFailureEvent $event): ?string
    {
        $passport = $event->getPassport();
        if ($passport !== null && $passport->hasBadge(UserBadge::class)) {
            $identifier = $passport->getBadge(UserBadge::class)->getUserIdentifier();
        } else {
            $identifier = trim($event->getRequest()->request->getString('email'));
        }

        // login_attempts.email is VARCHAR(254), which SQLite does not enforce; an over-long identifier (rejected
        // before the throttle even runs) would otherwise be stored in full (issue #22). Nothing that long is a
        // real account, so the cut cannot merge two accounts' counts.
        return $identifier !== '' ? mb_substr($identifier, 0, 254) : null;
    }

    /**
     * Realm ('user' | 'admin') a login failure belongs to, derived from the request path.
     * `^/admin` is exactly the admin firewall's pattern, so this always agrees with the
     * firewall that actually handled the request — no drift between INSERT and COUNT.
     */
    private function realmForRequest(Request $request): string
    {
        return str_starts_with($request->getPathInfo(), '/admin') ? 'admin' : 'user';
    }

    private function maybeApplyLockout(?string $email, string $realm): void
    {
        if ($email === null) {
            return;
        }

        // Admins are intentionally NOT hard-lockable (ADR-021): Admin has no lockout row and no
        // AdminChecker enforcement, and account-keyed lockout is a username-targeted DoS vector. A
        // failed admin-firewall login must therefore never create a lockout for a USER (ADR-003 firewall
        // isolation). Admin logins are still rate-limited above; they are simply never hard-locked here.
        if ($realm !== 'user') {
            return;
        }

        // Hard account lockout is INTENTIONALLY opt-in (default 0 = off), and that is the
        // safe default — not a fail-open gap. Brute force is already throttled fail-closed by
        // the rate limiter (rate_limit.max_attempts, default 10). Because this lockout is
        // account-keyed, enabling it by default would hand anyone a username-targeted DoS:
        // spam an account's failures to keep it locked. So it stays off unless an operator
        // deliberately turns it on.
        $lockoutMax = $this->configService->getInt('lockout.max_attempts', 0);
        if ($lockoutMax === 0) {
            return;
        }

        $cutoff = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
        $failureCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND email = ? AND attempted_at > ?',
            [$realm, $email, $cutoff]
        );

        if ($failureCount >= $lockoutMax) {
            $durationMinutes = $this->configService->getInt('lockout.duration_minutes', 15);
            $lockedUntil = (new \DateTimeImmutable())->modify("+{$durationMinutes} minutes")->format('Y-m-d H:i:s');

            // Was the account already under a live lock? Then the write below only refreshes it (issue #15).
            $alreadyLocked = $this->connection->fetchOne(
                'SELECT 1 FROM account_lockouts l JOIN "user" u ON u.id = l.user_id WHERE u.email = ? AND l.locked_until > ?',
                [$email, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
            ) !== false;

            // Write the lockout into the satellite, keyed by user_id resolved from the email. The
            // INSERT ... SELECT inserts nothing when no user has that email (so a non-existent account is
            // never "locked"); ON CONFLICT refreshes an existing lockout. This mirrors how login_attempts
            // is written here (raw DBAL), so no User is hydrated mid-failure-handling.
            $written = $this->connection->executeStatement(
                'INSERT INTO account_lockouts (user_id, locked_until) '
                . 'SELECT id, ? FROM "user" WHERE email = ? '
                . 'ON CONFLICT(user_id) DO UPDATE SET locked_until = excluded.locked_until',
                [$lockedUntil, $email]
            );

            // Announce a lockout only when an account was actually locked just now (issue #15): not for an email no
            // account has (nothing written — a false alert naming any address an attacker typed), and not again for
            // every further failure while the lock is already live.
            $webhookUrl = $this->configService->getString('webhook.lockout_url', '')
                ?: $this->configService->getString('webhook.global_url', '');
            if ($webhookUrl !== '' && $written > 0 && !$alreadyLocked) {
                $this->webhookDispatcher->dispatch($webhookUrl, [
                    'event_type'   => 'lockout',
                    'actor'        => $email,
                    'timestamp'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'ip'           => $this->requestStack->getCurrentRequest()?->getClientIp() ?? '0.0.0.0',
                    'locked_until' => $lockedUntil,
                ]);
            }
        }
    }
}
