<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Sanitises a caller-influenced redirect target down to a same-origin LOCAL path.
 *
 * The post-2FA redirect target (FEATURE-131 / review C17) is derived from the request the
 * user was intercepted on. Storing an absolute URL there let a spoofed Host header (or a
 * crafted request URI) turn the post-2FA redirect into an off-origin one. This guard accepts
 * ONLY a path-absolute, same-origin target and otherwise returns a safe fallback:
 *
 *   - must be a non-empty string starting with a single "/"
 *   - must NOT be protocol-relative ("//host" — browsers treat it as absolute)
 *   - must NOT start with "/\" or "\" (backslash is normalised to "/" by browsers,
 *     so "/\evil.com" behaves like "//evil.com")
 *   - must NOT contain CR/LF (header-injection defence)
 *
 * There is intentionally no allow-list of routes: a local path under the app's own firewall
 * is same-origin by construction, which is all this guard needs to guarantee.
 */
final class SafeRedirect
{
    public static function localPathOr(?string $candidate, string $fallback): string
    {
        return self::isLocalPath($candidate) ? $candidate : $fallback;
    }

    public static function isLocalPath(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        // Header-injection defence: a redirect target must never carry CR/LF.
        if (str_contains($candidate, "\r") || str_contains($candidate, "\n")) {
            return false;
        }

        // Must be path-absolute (single leading slash), never a bare relative or absolute URL.
        if ($candidate[0] !== '/') {
            return false;
        }

        // Reject protocol-relative "//host" and the backslash variants "/\", "\/" that
        // browsers normalise into an absolute, off-origin target.
        $second = $candidate[1] ?? '';
        if ($second === '/' || $second === '\\') {
            return false;
        }

        return true;
    }
}
