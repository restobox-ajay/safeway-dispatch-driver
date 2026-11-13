<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Security;

use App\Service\ConfigService;

/**
 * SSRF guard for outbound webhook delivery (review C19 / ADR-027).
 *
 * A webhook URL is a free-text, admin-configured value that flows into an outbound HTTP call.
 * This guard rejects any target that is not a public FQDN resolving exclusively to
 * publicly-routable addresses, using an IN-CODE (not admin-editable) blocklist for both IPv4
 * and IPv6 — including the IPv6 embedded-IPv4 bypass forms. The block is gated behind the
 * `webhook.block_internal_targets` switch (default ON / fail-closed); an operator who genuinely
 * needs internal delivery can turn it OFF. Non-http(s) schemes (file://, php://, gopher:// …)
 * are rejected UNCONDITIONALLY — that is a local-read/protocol-smuggling attack, not an
 * "internal target", so the switch does not apply to it.
 *
 * The pure evaluation ({@see evaluate()}) resolves the host to ALL A + AAAA records ONCE and
 * returns the validated IPs so the caller can pin the connection (no second resolution at
 * connect → no DNS-rebind TOCTOU).
 */
final class SsrfGuard
{
    public const CONFIG_KEY = 'webhook.block_internal_targets';

    /** Special-use / internal single-label TLDs a public webhook endpoint never legitimately uses. */
    private const INTERNAL_TLDS = ['local', 'localdomain', 'internal', 'localhost', 'lan', 'corp'];

    /** Internal multi-label suffixes (checked against the tail of the host). */
    private const INTERNAL_SUFFIXES = ['home.arpa'];

    /** @var list<array{0:string,1:int}> IPv4 network/prefix pairs that are never publicly routable. */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],      // "this" network / unspecified
        ['10.0.0.0', 8],     // RFC1918 private
        ['100.64.0.0', 10],  // CGNAT (RFC6598)
        ['127.0.0.0', 8],    // loopback
        ['169.254.0.0', 16], // link-local + cloud metadata 169.254.169.254
        ['172.16.0.0', 12],  // RFC1918 private
        ['192.168.0.0', 16], // RFC1918 private
        ['224.0.0.0', 4],    // multicast
        ['240.0.0.0', 4],    // reserved / broadcast
    ];

    /** @var list<array{0:string,1:int}> IPv6 network/prefix pairs that are never publicly routable. */
    private const BLOCKED_V6 = [
        ['::1', 128],   // loopback
        ['::', 128],    // unspecified
        ['fe80::', 10], // link-local
        ['fc00::', 7],  // unique-local (fc00::/7 covers fd00::/8)
        ['ff00::', 8],  // multicast
    ];

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    /** Whether internal-target blocking is enabled. Default ON (fail-closed) when unconfigured. */
    public function isEnabled(): bool
    {
        return $this->config->getBool(self::CONFIG_KEY, true);
    }

    /** Inspect a target for delivery, honouring the admin switch. */
    public function inspect(string $url): SsrfInspection
    {
        return self::evaluate($url, $this->isEnabled());
    }

    /**
     * Pure decision core. Rejects non-http(s) schemes unconditionally; when $blockingEnabled is
     * true it additionally rejects non-public FQDNs and any host resolving to a blocked address.
     * On allow, resolves the host ONCE and returns the validated IPs for connection pinning.
     */
    public static function evaluate(string $url, bool $blockingEnabled): SsrfInspection
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return SsrfInspection::deny('malformed-url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            // Unconditional — the switch never re-opens file://, php://, gopher:// …
            return SsrfInspection::deny('scheme');
        }

        $host = trim((string) $parts['host'], '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        // IP literal target: no DNS, pin to itself.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($blockingEnabled && self::isBlockedIp($host)) {
                return SsrfInspection::deny('blocked-ip');
            }

            return SsrfInspection::allow($host, $port, [$host]);
        }

        // Hostname target.
        if ($blockingEnabled && !self::isPublicFqdn($host)) {
            return SsrfInspection::deny('not-public-fqdn');
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            return SsrfInspection::deny('unresolvable');
        }

        if ($blockingEnabled) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip)) {
                    // ANY resolved address in a blocked range refuses the whole delivery.
                    return SsrfInspection::deny('blocked-ip');
                }
            }
        }

        return SsrfInspection::allow($host, $port, $ips);
    }

    /**
     * Backwards-compatible boolean gate (blocking always ON). Used by the webhook dispatcher's
     * legacy static entry point and by unit tests that assert the default posture.
     */
    public static function isAllowedUrl(string $url): bool
    {
        return self::evaluate($url, true)->allowed;
    }

    /**
     * True when $ip falls in a non-publicly-routable range. IPv6 embedded-IPv4 forms
     * (IPv4-mapped, 6to4, NAT64, Teredo) are unwrapped and the embedded IPv4 re-checked against
     * the IPv4 blocklist, so e.g. ::ffff:127.0.0.1 or 64:ff9b::7f00:1 cannot smuggle a loopback.
     */
    public static function isBlockedIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            // Unparseable → fail closed (treat as blocked).
            return true;
        }

        if (strlen($bin) === 4) {
            return self::binInAnyRange($bin, self::BLOCKED_V4);
        }

        // 16-byte IPv6. First unwrap any embedded IPv4 and re-check it as IPv4.
        $embedded = self::extractEmbeddedV4($bin);
        if ($embedded !== null && self::binInAnyRange($embedded, self::BLOCKED_V4)) {
            return true;
        }

        return self::binInAnyRange($bin, self::BLOCKED_V6);
    }

    /**
     * True only for a syntactically public FQDN: a multi-label hostname whose TLD is not a
     * special-use/internal one. Single-label hosts (localhost, db, intranet — resolved via
     * search domains / hosts / mDNS) and internal TLDs (.local, .localdomain, .internal,
     * .localhost, .home.arpa, .lan, .corp) are rejected. The .localdomain suffix is the
     * canonical loopback alias on many distros (localhost.localdomain -> 127.0.0.1), so it is
     * refused at the name layer rather than relying on the target happening to resolve to a
     * blocked IP.
     */
    public static function isPublicFqdn(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || str_contains($host, ':')) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2 || in_array('', $labels, true)) {
            // Single-label (no TLD) or an empty label (e.g. "a..b") — not a public FQDN.
            return false;
        }

        $tld = end($labels);
        if (in_array($tld, self::INTERNAL_TLDS, true)) {
            return false;
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to every A and AAAA address (or the literal itself when given an IP).
     *
     * @return list<string>
     */
    public static function resolveHost(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Unwrap an IPv6 address that embeds an IPv4 address, returning the 4-byte IPv4 (binary)
     * or null when no embedded IPv4 is present. Covers IPv4-mapped (::ffff:0:0/96),
     * 6to4 (2002::/16), NAT64 (64:ff9b::/96) and Teredo (2001::/32, last 32 bits XOR 0xffffffff).
     */
    private static function extractEmbeddedV4(string $bin16): ?string
    {
        // IPv4-mapped ::ffff:0:0/96 (and the deprecated IPv4-compatible ::0:0/96 with high bytes 0).
        if (substr($bin16, 0, 10) === str_repeat("\x00", 10)
            && (substr($bin16, 10, 2) === "\xff\xff" || substr($bin16, 10, 2) === "\x00\x00")) {
            return substr($bin16, 12, 4);
        }

        // 6to4 2002::/16 → embedded IPv4 is bytes 2..5.
        if (substr($bin16, 0, 2) === "\x20\x02") {
            return substr($bin16, 2, 4);
        }

        // NAT64 64:ff9b::/96 → embedded IPv4 is the last 4 bytes.
        if (substr($bin16, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) {
            return substr($bin16, 12, 4);
        }

        // Teredo 2001:0000::/32 → client IPv4 is the last 4 bytes XOR 0xffffffff.
        if (substr($bin16, 0, 4) === "\x20\x01\x00\x00") {
            $obfuscated = substr($bin16, 12, 4);
            return $obfuscated ^ "\xff\xff\xff\xff";
        }

        return null;
    }

    /** @param list<array{0:string,1:int}> $ranges */
    private static function binInAnyRange(string $binIp, array $ranges): bool
    {
        foreach ($ranges as [$network, $prefix]) {
            if (self::binInCidr($binIp, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function binInCidr(string $binIp, string $network, int $prefixBits): bool
    {
        $binNet = @inet_pton($network);
        if ($binNet === false || strlen($binNet) !== strlen($binIp)) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        if ($fullBytes > 0 && substr($binIp, 0, $fullBytes) !== substr($binNet, 0, $fullBytes)) {
            return false;
        }

        $remBits = $prefixBits % 8;
        if ($remBits !== 0) {
            $mask = 0xff << (8 - $remBits) & 0xff;
            if ((ord($binIp[$fullBytes]) & $mask) !== (ord($binNet[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
