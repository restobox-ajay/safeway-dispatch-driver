<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\EventListener;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\Auth2fa\Security\TwoFactorGuard;
use App\Entity\User;
use App\Security\TwoFactorEnforcementResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Moved into auth-2fa-bundle (FEATURE-143 / ADR-043): with the bundle absent this listener does not
 * exist, so no user is ever redirected to a (now non-existent) 2FA challenge/setup route. Reads the
 * user's enrolment from the two_factor_settings satellite; TwoFactorEnforcementResolver stays in core.
 */
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -20)]
class TwoFactorChallengeListener
{
    /** Routes where an authenticator signs the user in from a one-shot credential in the URL. */
    private const SIGN_IN_ENTRY_ROUTES = ['app_magic_link_verify'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
        private TwoFactorEnforcementResolver $enforcementResolver,
        private TwoFactorGuard $twoFactorGuard,
        private TwoFactorSettingsRepository $settings,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Skip dev profiler and asset routes
        if (str_starts_with($path, '/_')) {
            return;
        }

        // Skip the 2FA challenge route itself to avoid redirect loops. Matched EXACTLY (not by
        // prefix) so a future `/2fa*` route cannot silently escape the challenge gate.
        if ($path === '/2fa/challenge') {
            return;
        }

        // Skip logout so users can always log out
        if (str_starts_with($path, '/logout')) {
            return;
        }

        // Skip the forced password-change route to avoid cross-listener redirect loops
        if (str_starts_with($path, '/account/change-expired-password')) {
            return;
        }

        // Skip stateless API firewall requests — auth is handled by TokenAuthenticator
        if (str_starts_with($path, '/api')) {
            return;
        }

        // Skip impersonation routes and skip 2FA during active impersonation
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

        // Per-role enforcement (FEATURE-126): resolve the effective level from the principal's
        // roles. A plain user has no per-role key configured, so this falls back to the legacy
        // global `2fa.enforcement` key — behaviour-preserving for the user realm.
        $enforcement = $this->enforcementResolver->resolveForRoles($user->getRoles());

        // When off, skip all 2FA logic entirely (no challenge even for users with 2FA enabled)
        if ($enforcement === 'off') {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (!$this->settings->isEnabled($user)) {
            // When required and user has no 2FA, redirect to setup. Exempt ONLY the setup route
            // itself (exact match) to avoid a setup->setup loop. A broad `/account/2fa` prefix
            // match would let any future `/account/2fa*` route escape the enrolment gate.
            if ($enforcement === 'required' && $path !== '/account/2fa/setup') {
                $event->setResponse(new RedirectResponse($this->router->generate('app_2fa_setup')));
            }
            return;
        }

        // Trusted IP / trusted device / already-verified are all handled by the shared guard,
        // so the controller-side check on skip-listed routes agrees with this listener.
        if (!$this->twoFactorGuard->isChallengePending($request, $user)) {
            return;
        }

        // Save the intended URL before redirecting to challenge. Store a LOCAL path
        // (getRequestUri = path + query, no scheme/host) rather than the absolute getUri(),
        // whose host comes from the client Host header — so the post-2FA redirect is
        // same-origin by construction (FEATURE-131 / review C17). The controller re-validates
        // it through SafeRedirect before redirecting.
        // Never park a one-shot sign-in entry (the magic-link verify URL carries its plaintext token): returning
        // there after the challenge re-runs that authenticator on a used token and fails (issue #60). The
        // controller then falls back to /dashboard, which is where that entry route sends a user anyway.
        if (in_array($request->attributes->get('_route'), self::SIGN_IN_ENTRY_ROUTES, true)) {
            $session->remove('_2fa_target_url');
        } else {
            $session->set('_2fa_target_url', $request->getRequestUri());
        }

        $challengeUrl = $this->router->generate('app_2fa_challenge');
        $event->setResponse(new RedirectResponse($challengeUrl));
    }
}
