<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Bundle\AuthWebhook\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-128 (review C39): adversarial test for the SSRF guard's layered defence against a
 * hostname target that would reach an internal address.
 *
 * `localhost.localdomain` is the canonical loopback alias on many distros (127.0.0.1 / ::1). It is
 * now refused at the NAME layer — `.localdomain` is a special-use internal TLD — so the guard never
 * even resolves it (closes the "block localhost / localdomain" gap). Before that hardening the host
 * passed the public-FQDN gate and was caught only by the second, DNS-resolution layer, which relied
 * on the environment actually mapping the name to a loopback address.
 *
 * This test pins BOTH layers:
 *  - the name gate refuses the host outright (deterministic, no DNS), and
 *  - defence-in-depth: whenever the host DOES resolve, every resolved address is a blocked one, so
 *    even if the name gate were bypassed the resolution layer would still refuse delivery.
 */
final class SsrfHostResolutionTest extends TestCase
{
    private const HOST = 'localhost.localdomain';

    public function testLocalDomainHostIsRefusedAtTheNameLayer(): void
    {
        // Layer 1 — name gate: .localdomain is internal, so the host is not a public FQDN and is
        // rejected before any DNS resolution happens.
        self::assertFalse(
            SsrfGuard::isPublicFqdn(self::HOST),
            self::HOST . ' must be rejected at the public-FQDN gate (.localdomain is internal)'
        );

        $result = SsrfGuard::evaluate('http://' . self::HOST . '/hook', true);
        self::assertFalse($result->allowed, self::HOST . ' must be blocked');
        self::assertSame('not-public-fqdn', $result->reason);
    }

    public function testDefenceInDepthAnyResolvedAddressIsBlocked(): void
    {
        // Layer 2 — DNS resolution: independent of the name gate, if this loopback alias resolves
        // in the current environment it must resolve ONLY to blocked addresses. (Where the alias is
        // absent, e.g. a minimal container without the /etc/hosts entry, the set is empty.)
        $ips = SsrfGuard::resolveHost(self::HOST);
        self::assertIsList($ips);
        foreach ($ips as $ip) {
            self::assertTrue(
                SsrfGuard::isBlockedIp($ip),
                self::HOST . ' resolved to a publicly-routable address (' . $ip . '); expected only blocked IPs'
            );
        }
    }
}
