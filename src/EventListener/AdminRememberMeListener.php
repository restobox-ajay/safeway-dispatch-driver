<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Session\SessionTtlResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * "Remember me" for the admin login form (ADR-051).
 *
 * Deliberately NOT Symfony's remember_me: that issues a long-lived bearer cookie which survives
 * revocation, and ADR-049 records that adding one to the admin realm would reopen review finding C3
 * (a deleted / deactivated / "terminate all sessions"-ed admin keeping access) unless an
 * Admin-side `sessionsInvalidatedAt` marker were bound into its HMAC.
 *
 * Instead the SESSION itself is extended: ticking the box sets {@see SessionTtlResolver::LONG_SESSION_KEY},
 * which makes the handler stamp the much longer `session.remember_me_lifetime_days` TTL (re-stamped on
 * every request, so it slides on activity). This keeps every existing revocation path working
 * unchanged — "terminate all" deletes the admin_sessions row and AdminSessionRequestListener deauths
 * on the next request — because there is no bearer credential to outlive it. It also means the
 * session-scoped 2FA flag (`_admin_2fa_verified`) rides along, so a remembered admin is not
 * re-challenged for TOTP within the window.
 *
 * Separately, and only when the box is ticked, the admin's email is stored in a long-lived cookie so
 * the login form prefills it on a later visit (`last_username` is session-scoped and does not survive
 * an expired session). Opt-in only: an admin who does not tick the box leaves nothing behind.
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
#[AsEventListener(event: LogoutEvent::class, method: 'onLogout')]
#[AsEventListener(event: ResponseEvent::class, method: 'onResponse')]
class AdminRememberMeListener
{
    use InteractiveFirewallTrait;

    /** Request parameter emitted by the admin login form's checkbox. */
    public const REQUEST_PARAM = '_remember_me';

    /** Long-lived, HttpOnly cookie holding ONLY the email, for form prefill. */
    public const EMAIL_COOKIE = 'ADMIN_LAST_EMAIL';

    /** Request attribute carrying a cookie to attach to this request's response. */
    private const PENDING_COOKIE = '_admin_remember_me_cookie';

    public function __construct(
        #[Autowire('%env(SESSION_COOKIE_DOMAIN)%')] private readonly string $cookieDomain = '',
    ) {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $admin = $event->getUser();
        if (!$admin instanceof Admin) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $remember = $request->request->get(self::REQUEST_PARAM) !== null;
        $session  = $request->getSession();

        if (!$remember) {
            // Not ticked: baseline idle window, and clear any email left by a previous opt-in.
            $session->remove(SessionTtlResolver::LONG_SESSION_KEY);
            $request->attributes->set(self::PENDING_COOKIE, $this->expireEmailCookie($request->isSecure()));

            return;
        }

        $session->set(SessionTtlResolver::LONG_SESSION_KEY, true);
        $request->attributes->set(
            self::PENDING_COOKIE,
            $this->emailCookie($admin->getEmail(), $request->isSecure()),
        );
    }

    /**
     * Explicit logout drops the prefill cookie. The long-session flag needs no cleanup — it lives in
     * the session being destroyed.
     */
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null || !$token->getUser() instanceof Admin) {
            return;
        }

        $request = $event->getRequest();
        $request->attributes->set(self::PENDING_COOKIE, $this->expireEmailCookie($request->isSecure()));

        $event->getResponse()?->headers->setCookie($this->expireEmailCookie($request->isSecure()));
    }

    /** Attach any cookie staged by the login/logout handlers above onto the outgoing response. */
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $cookie = $event->getRequest()->attributes->get(self::PENDING_COOKIE);
        if ($cookie instanceof Cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }

    private function emailCookie(string $email, bool $secure): Cookie
    {
        return new Cookie(
            self::EMAIL_COOKIE,
            $email,
            time() + (SessionTtlResolver::DEFAULT_REMEMBER_ME_DAYS * 86400),
            '/',
            $this->cookieDomain !== '' ? $this->cookieDomain : null,
            $secure,
            true,  // HttpOnly — read server-side only, never by JS.
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    private function expireEmailCookie(bool $secure): Cookie
    {
        return new Cookie(
            self::EMAIL_COOKIE,
            null,
            1,
            '/',
            $this->cookieDomain !== '' ? $this->cookieDomain : null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
