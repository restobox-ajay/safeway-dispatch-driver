<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\EventListener;

use App\Bundle\AuthIpWhitelist\Repository\UserIpWhitelistRepository;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\IpWhitelistedAuthenticatorInterface;
use App\Service\ConfigService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Enforces the login IP whitelist (FEATURE-146 / ADR-046). Moved into auth-ip-whitelist-bundle so that with
 * the bundle unregistered there is NO IP restriction on login at all: the listener is simply not in the
 * container. The global whitelist still lives in the core `config` store (edited via the bundle-owned
 * IpWhitelistConfigPage); the per-user override now lives in the bundle's `user_ip_whitelist` satellite,
 * read here through UserIpWhitelistRepository (core `User` no longer carries it).
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 100)]
final class IpWhitelistListener
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
        private readonly UserIpWhitelistRepository $whitelistRepository,
    ) {}

    public function __invoke(CheckPassportEvent $event): void
    {
        // Enforce the IP whitelist for every INTERACTIVE login authenticator:
        // form login (user + admin firewalls) and any authenticator that opts in
        // via App\Security\IpWhitelistedAuthenticatorInterface — the passwordless
        // magic link (owned by auth-magic-link-bundle, FEATURE-140) carries that
        // marker. A magic link must not be a whitelist bypass (review C20 /
        // FEATURE-113). The marker keeps this listener decoupled from the other
        // optional bundle: with that bundle absent no class implements the marker.
        //
        // Every other authenticator is intentionally exempt:
        //  - ImpersonationAuthenticator: an admin-initiated switch. The admin
        //    already passed the admin firewall's own IP checks, and the target
        //    user is not physically present to satisfy their own restriction.
        //  - TokenAuthenticator / AdminTokenAuthenticator (API PATs): these are
        //    non-interactive machine credentials, out of scope for the
        //    interactive-login whitelist.
        $authenticator = $event->getAuthenticator();
        if (!$authenticator instanceof FormLoginAuthenticator
            && !$authenticator instanceof IpWhitelistedAuthenticatorInterface
        ) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';

        // Determine which firewall by checking the request path
        $isAdminFirewall = str_starts_with($request->getPathInfo(), '/admin');

        if ($isAdminFirewall) {
            $globalWhitelist = $this->configService->getString('ip_whitelist.admin_ips', '');
            $this->checkIp($ip, $globalWhitelist);
            return;
        }

        // User firewall: check per-user override first, then global
        $globalWhitelist = $this->configService->getString('ip_whitelist.user_ips', '');

        $effectiveWhitelist = $globalWhitelist;

        $passport = $event->getPassport();
        if ($passport->hasBadge(UserBadge::class)) {
            $email = $passport->getBadge(UserBadge::class)->getUserIdentifier();
            $user = $this->userRepository->findByEmail($email);
            if ($user instanceof User) {
                $override = $this->whitelistRepository->getAllowedIps($user);
                if ($override !== null && $override !== '') {
                    $effectiveWhitelist = $override;
                }
            }
        }

        $this->checkIp($ip, $effectiveWhitelist);
    }

    private function checkIp(string $ip, string $whitelist): void
    {
        if ($whitelist === '') {
            return;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
        if (empty($allowed)) {
            return;
        }

        if (!IpUtils::checkIp($ip, $allowed)) {
            throw new CustomUserMessageAuthenticationException('Login from your IP address is not allowed.');
        }
    }
}
