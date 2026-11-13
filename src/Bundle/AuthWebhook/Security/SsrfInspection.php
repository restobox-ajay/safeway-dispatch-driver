<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Security;

/**
 * The outcome of inspecting a webhook target URL against the SSRF guard.
 *
 * When {@see $allowed} is true, {@see $pinnedIps} carries the validated address(es) the
 * connection must be pinned to (via cURL CURLOPT_RESOLVE) so no second, unchecked DNS
 * resolution happens at connect time. {@see $host}/{@see $port} are the original target's
 * hostname and effective port (needed to build the CURLOPT_RESOLVE entry while preserving
 * the Host header / TLS SNI).
 */
final class SsrfInspection
{
    /** @param list<string> $pinnedIps */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly array $pinnedIps = [],
    ) {
    }

    /** @param list<string> $pinnedIps */
    public static function allow(string $host, int $port, array $pinnedIps): self
    {
        return new self(true, null, $host, $port, $pinnedIps);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
