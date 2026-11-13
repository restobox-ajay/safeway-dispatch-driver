<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Default authenticator on the `user` firewall's custom_authenticators list when the optional
 * auth-magic-link-bundle is NOT registered (FEATURE-140). Its supports() returns false, so it never
 * authenticates: with the bundle absent the /magic-link* routes are bundle-owned and 404, and this
 * null authenticator simply keeps the firewall compilable (all firewalls must live in one file, so
 * the magic-link authenticator cannot be added/removed from security.yaml per deploy).
 *
 * When the bundle IS registered, its compiler pass re-aliases `app.magic_link_authenticator` to the
 * real {@see \App\Bundle\AuthMagicLink\Security\MagicLinkAuthenticator}, so this is never used.
 */
final class NullMagicLinkAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        // Unreachable: supports() is false, so the firewall never calls authenticate().
        throw new \LogicException('NullMagicLinkAuthenticator cannot authenticate; the auth-magic-link-bundle is not registered.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
