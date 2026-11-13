<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Moved into auth-2fa-bundle (FEATURE-143 / ADR-043). Since the user's TOTP secret no longer lives on
 * the {@see User} entity (it moved to the two_factor_settings satellite), the secret is now passed in
 * explicitly by the caller (which resolves it from TwoFactorSettingsRepository). Binding semantics
 * (FEATURE-132) are unchanged: the bypass cookie is bound to (current totpSecret + status).
 */
final class TrustedDeviceManager
{
    public const COOKIE_NAME = 'TRUSTED_DEVICE';

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    public function generateCookie(User $user, ?string $totpSecret, int $lifetimeDays, bool $secure): Cookie
    {
        $expires = time() + $lifetimeDays * 86400;
        $value = $this->buildCookieValue((int) $user->getId(), $expires, $this->bindingToken($totpSecret, $user->getStatus()));

        // $secure is derived from $request->isSecure() at the call site (mirrors
        // ConfigAwareRememberMeHandler): the Secure attribute is emitted over HTTPS so this
        // 2FA-bypass bearer credential never leaks on the wire, while local http testing is
        // unaffected. HttpOnly + SameSite=Strict are kept (Strict is appropriate for a
        // credential that skips the 2FA challenge).
        return new Cookie(
            self::COOKIE_NAME,
            $value,
            $expires,
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public function isDeviceTrusted(Request $request, User $user, ?string $totpSecret): bool
    {
        $raw = $request->cookies->get(self::COOKIE_NAME);
        if ($raw === null) {
            return false;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }

        [$storedUserId, $expiresStr, $hmac] = $parts;

        if ((int) $storedUserId !== (int) $user->getId()) {
            return false;
        }

        if ((int) $expiresStr <= time()) {
            return false;
        }

        // Recompute the HMAC against the user's CURRENT binding token, not the one baked in
        // when the cookie was issued. A 2FA reset/disable/re-enroll (totpSecret rotates or
        // clears) or a deactivation (status -> inactive) changes the binding, so a cookie
        // minted under the old state no longer matches and stops skipping the 2FA challenge
        // (FEATURE-132 / review C16).
        $expected = $this->computeHmac($storedUserId, $expiresStr, $this->bindingToken($totpSecret, $user->getStatus()));

        return hash_equals($expected, $hmac);
    }

    /**
     * Per-user token folded into the cookie HMAC so the cookie is invalidated whenever the
     * bypass must no longer be honoured. It is NOT stored in the cookie: validation recomputes
     * it from the current user state.
     *
     *  - totpSecret: a 2FA reset / disable / re-enroll clears or rotates the seed.
     *  - status: deactivation / soft-delete flips it to 'inactive' (FEATURE-102 consistency).
     */
    public function bindingToken(?string $totpSecret, string $status): string
    {
        return hash('sha256', ($totpSecret ?? '') . '|' . $status);
    }

    private function buildCookieValue(int $userId, int $expires, string $binding): string
    {
        $userStr = (string) $userId;
        $expStr = (string) $expires;
        $hmac = $this->computeHmac($userStr, $expStr, $binding);

        return base64_encode($userStr . ':' . $expStr . ':' . $hmac);
    }

    private function computeHmac(string $userId, string $expires, string $binding): string
    {
        return hash_hmac('sha256', $userId . ':' . $expires . ':' . $binding, $this->secret);
    }
}
