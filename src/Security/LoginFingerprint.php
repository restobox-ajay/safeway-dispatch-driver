<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * FEATURE-107 (review C14): the single source of truth for the device fingerprint used by the
 * login-history and login-notification listeners. Defining the formula in exactly one place makes
 * it impossible for the two listeners to drift (a byte-different formula would otherwise turn every
 * login into a "new device" alert).
 */
final class LoginFingerprint
{
    /**
     * The device fingerprint: a stable hash of the request IP and User-Agent.
     */
    public function fingerprint(string $ip, string $userAgent): string
    {
        return hash('sha256', $ip . $userAgent);
    }

    /**
     * Extract the normalised (ip, userAgent, fingerprint) triple from a request, applying the same
     * IP default and User-Agent truncation both listeners rely on.
     *
     * @return array{ip: string, userAgent: string, fingerprint: string}
     */
    public function contextFromRequest(Request $request): array
    {
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $userAgent = substr($request->headers->get('User-Agent', ''), 0, 512);

        return [
            'ip'          => $ip,
            'userAgent'   => $userAgent,
            'fingerprint' => $this->fingerprint($ip, $userAgent),
        ];
    }
}
