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
 * Default authenticator for the `api` firewall when the optional auth-pat-bundle is NOT registered
 * (FEATURE-138). Its supports() returns false, so it never authenticates: an /api request falls
 * through the firewall to routing, where — the /api routes being bundle-owned — it 404s. This is what
 * lets the `api` firewall live permanently in security.yaml (firewalls must all be in one file) while
 * the PAT feature stays genuinely optional.
 *
 * When the bundle IS registered, its compiler pass re-aliases `app.api_authenticator` to the real
 * {@see \App\Bundle\AuthPat\Security\TokenAuthenticator}, so this null authenticator is never used.
 */
final class NullApiAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        // Unreachable: supports() is false, so the firewall never calls authenticate().
        throw new \LogicException('NullApiAuthenticator cannot authenticate; the auth-pat-bundle is not registered.');
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
