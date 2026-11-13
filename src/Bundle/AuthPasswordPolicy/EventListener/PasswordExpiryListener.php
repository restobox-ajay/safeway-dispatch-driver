<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\EventListener;

use App\Bundle\AuthPasswordPolicy\Security\PasswordExpiryChecker;
use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Redirects an authenticated user whose password has expired to the (core) forced-change page. Moved into
 * auth-password-policy-bundle (FEATURE-145 / ADR-045): it is autoconfigured as a kernel.request listener
 * only when the bundle is registered, so with the bundle absent there is no expiry enforcement at all.
 * The target route `app_account_change_expired_password` stays a CORE route (AccountController).
 */
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -25)]
class PasswordExpiryListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
        private PasswordExpiryChecker $expiryChecker,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/_')) {
            return;
        }

        if (str_starts_with($path, '/logout')) {
            return;
        }

        // Skip 2FA challenge/setup routes — 2FA must be resolved before expiry is checked
        if (str_starts_with($path, '/2fa')) {
            return;
        }

        // Skip the forced-change page itself to avoid redirect loops
        if (str_starts_with($path, '/account/change-expired-password')) {
            return;
        }

        // Skip stateless API firewall requests — no password-expiry redirect for token auth
        if (str_starts_with($path, '/api')) {
            return;
        }

        // Skip impersonation routes and skip expiry checks during active impersonation
        if (str_starts_with($path, '/impersonate')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Only for the impersonated user: the flag survives a later sign-in as someone else in the same session.
        if ($request->hasSession() && $request->getSession()->get('_impersonating_as') === $user->getUserIdentifier()) {
            return;
        }

        if (!$this->expiryChecker->isExpired($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->router->generate('app_account_change_expired_password')
        ));
    }
}
