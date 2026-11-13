<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Security\TwoFactorEnforcementResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The admin-realm mirror of {@see TwoFactorChallengeListener} (FEATURE-126). Intercepts admin
 * (`^/admin`, session-backed) requests and, per the resolved per-role enforcement level:
 *  - redirects an enrolled admin who still owes a TOTP challenge this session to the admin
 *    challenge page, and
 *  - redirects a `required`-but-unenrolled admin to admin 2FA setup.
 *
 * Realm-isolated (ADR-003): its own session keys (`_admin_2fa_verified` / `_admin_2fa_target_url`),
 * its own routes, and it only ever acts on an {@see Admin} principal. The user listener already
 * `return`s for a non-`User` principal, so the two never double-handle the same request.
 */
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -20)]
class AdminTwoFactorChallengeListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
        private TwoFactorEnforcementResolver $enforcementResolver,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only the interactive admin firewall area. The stateless admin API firewall
        // (^/admin-api) authenticates per request via a bearer token — never challenge it.
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '/admin-api')) {
            return;
        }

        // Public / self-service admin auth routes must stay reachable, and the challenge route
        // itself must not redirect to itself (loop guard). NOTE: only the challenge route is
        // skipped here — NOT the whole /admin/2fa prefix. Setup and disable stay subject to the
        // challenge below so an enrolled admin who still owes a TOTP challenge is bounced to the
        // challenge before they can self-disable the second factor (review C37 BLOCKER; mirrors
        // the user realm, where challenge `/2fa` and setup/disable `/account/2fa` are distinct).
        // The challenge route is matched EXACTLY (not by prefix) so a future `/admin/2fa/challenge*`
        // route cannot silently escape the challenge gate. The public auth routes are matched exactly too:
        // a `/admin/login` prefix also exempted the protected `/admin/login-history` (issue #63). Only the
        // reset route, which carries its token in the path, is a prefix — and only below `/admin/reset-password/`.
        if (
            $path === '/admin/login'
            || $path === '/admin/logout'
            || $path === '/admin/forgot-password'
            || $path === '/admin/forgot-password/check'
            || str_starts_with($path, '/admin/reset-password/')
            || $path === '/admin/2fa/challenge'
        ) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof Admin) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Skip while an admin is impersonating another admin — the target admin never did an
        // interactive login, so there is no second factor to demand (mirrors the user side). Only for
        // the impersonated admin: the flag survives a later sign-in as someone else in the same session.
        if ($session->get('_impersonating_admin_as') === $user->getUserIdentifier()) {
            return;
        }

        $enforcement = $this->enforcementResolver->resolveForRoles($user->getRoles());
        // "off" only means nobody is forced to enrol. An admin who enrolled is always asked for the code:
        // otherwise anyone able to change the setting could switch off every superadmin's 2FA (audit #2).
        if ($enforcement === 'off' && !$user->isTotpEnabled()) {
            return;
        }

        if (!$user->isTotpEnabled()) {
            // Required but not enrolled: force setup. Exempt ONLY the setup page itself (exact
            // match) to avoid a setup->setup loop. A broad `/admin/2fa` prefix match would let any
            // future `/admin/2fa*` route escape the enrolment gate, so the required-but-unenrolled
            // admin is redirected to setup from everywhere except the setup route.
            if ($enforcement === 'required' && $path !== '/admin/2fa/setup') {
                $event->setResponse(new RedirectResponse($this->router->generate('app_admin_2fa_setup')));
            }
            return;
        }

        // Enrolled: challenge once per session per admin. The marker holds the id of the admin who passed,
        // so a session that later signs in as another admin (no logout) is challenged again.
        if ($user->getId() !== null && $session->get('_admin_2fa_verified') === $user->getId()) {
            return;
        }

        // Save the intended URL as a LOCAL path (path + query, no host) so the post-challenge
        // redirect is same-origin by construction (mirrors FEATURE-131 / review C17).
        $session->set('_admin_2fa_target_url', $request->getRequestUri());

        $event->setResponse(new RedirectResponse($this->router->generate('app_admin_2fa_challenge')));
    }
}
