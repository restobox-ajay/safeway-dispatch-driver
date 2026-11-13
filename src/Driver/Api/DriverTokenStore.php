<?php

declare(strict_types=1);

namespace App\Driver\Api;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Where a signed-in driver's Safeway API tokens live: the driver's own PHP session (session files on this server,
 * no database). The browser holds only the session cookie, never a token.
 *
 * Safeway rotates the refresh token on every use, so two requests of one driver must never refresh at once. PHP's
 * file session handler locks the session file from session start to the end of the request, so the requests of
 * one session already run one after the other, and the second reads the pair the first saved.
 */
final class DriverTokenStore
{
    public const SESSION_KEY = '_driver_api_tokens';

    public function __construct(private readonly RequestStack $requests) {}

    /** The signed-in driver's tokens, or null when this browser session has none. */
    public function current(): ?DriverTokens
    {
        $raw = $this->session()?->get(self::SESSION_KEY);

        return \is_array($raw) ? DriverTokens::fromArray($raw) : null;
    }

    /**
     * After a successful login: a fresh session id holding the new tokens.
     *
     * @param array<string, mixed> $driver
     */
    public function start(string $accessToken, string $refreshToken, int $expiresIn, array $driver, string $deviceId): DriverTokens
    {
        $tokens = new DriverTokens($accessToken, $refreshToken, time() + max(1, $expiresIn), $driver, $deviceId);
        $session = $this->session();
        $session?->migrate(true);
        $session?->set(self::SESSION_KEY, $tokens->toArray());

        return $tokens;
    }

    /**
     * Renews the access token with the refresh token and keeps the new pair.
     *
     * @param callable(string $refreshToken): array{access_token: string, refresh_token: string, expires_in?: int, driver?: array<string, mixed>|null} $refresh
     */
    public function refresh(DriverTokens $tokens, callable $refresh): DriverTokens
    {
        $res = $refresh($tokens->getRefreshToken());
        $next = $tokens->withTokens($res['access_token'], $res['refresh_token'], (int) ($res['expires_in'] ?? 900), time());
        if (\is_array($res['driver'] ?? null)) {
            $next = $next->withDriver($res['driver']);
        }
        $this->session()?->set(self::SESSION_KEY, $next->toArray());

        return $next;
    }

    /** @param array<string, mixed> $driver */
    public function updateDriver(array $driver): void
    {
        $tokens = $this->current();
        if ($tokens !== null) {
            $this->session()?->set(self::SESSION_KEY, $tokens->withDriver($driver)->toArray());
        }
    }

    /** Forgets the tokens (logout, or the API refused to renew them). */
    public function end(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requests->getMainRequest();

        return $request !== null && $request->hasSession() ? $request->getSession() : null;
    }
}
