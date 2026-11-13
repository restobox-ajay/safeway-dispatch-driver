<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Marker for an INTERACTIVE-login authenticator that must be subject to the IP whitelist
 * (review C20 / FEATURE-113). {@see \App\Bundle\AuthIpWhitelist\EventListener\IpWhitelistListener} enforces
 * the whitelist for Symfony's FormLoginAuthenticator (a framework class it cannot mark) and for any
 * authenticator carrying this marker.
 *
 * The marker stays in CORE — not in auth-ip-whitelist-bundle — so it decouples the two optional bundles from
 * each other: the magic-link authenticator (auth-magic-link-bundle, FEATURE-140) implements it, and the
 * enforcing listener (auth-ip-whitelist-bundle, FEATURE-146) checks it, without either bundle depending on
 * the other. With the magic-link bundle absent the listener simply never sees a class implementing this
 * marker; with the ip-whitelist bundle absent there is no listener at all. A future bundle authenticator that
 * should also be whitelist-gated opts in by implementing this interface, no core change required.
 */
interface IpWhitelistedAuthenticatorInterface
{
}
