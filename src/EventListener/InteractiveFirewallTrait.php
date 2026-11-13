<?php

declare(strict_types=1);

namespace App\EventListener;

/**
 * Shared guard for security-event listeners that must only react to interactive,
 * session-backed form logins — never to stateless bearer-token (PAT) authentication.
 *
 * The stateless `api` / `admin_api` firewalls resolve principals from the same
 * `app_users` / `app_admins` providers as the interactive firewalls, so an
 * `instanceof User` / `instanceof Admin` check does NOT distinguish a real login
 * from an API call. The firewall name does (see review finding C2 / FEATURE-097).
 */
trait InteractiveFirewallTrait
{
    /** Firewalls whose logins are real, interactive, session-backed logins. */
    private const INTERACTIVE_FIREWALLS = ['user', 'admin'];

    private function isInteractiveFirewall(string $firewallName): bool
    {
        return \in_array($firewallName, self::INTERACTIVE_FIREWALLS, true);
    }
}
