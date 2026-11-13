<?php

declare(strict_types=1);

namespace App\Security\Driver;

use App\Driver\Api\DriverApiClient;
use App\Driver\Api\DriverApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Driver login: the phone number or employee number and the PIN are checked by the Safeway Dispatch Driver API
 * (POST /auth/login), with its lockouts and rate limit; this app never sees or stores the PIN. The driver stays
 * signed in as long as the session cookie lives (framework.session.cookie_lifetime, 30 days).
 */
final class DriverPinAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const LOGIN_ROUTE = 'app_driver_login';
    public const HOME_ROUTE = 'app_driver_tasks';
    public const CSRF_ID = 'driver_login';
    public const DEVICE_COOKIE = 'swd_device';

    public function __construct(
        private readonly DriverApiClient $api,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === self::LOGIN_ROUTE;
    }

    public function authenticate(Request $request): Passport
    {
        $login = trim((string) $request->request->get('login', ''));
        $pin = (string) $request->request->get('pin', '');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $login);
        if ($login === '' || $pin === '') {
            throw new CustomUserMessageAuthenticationException('Enter your phone number or employee number and your PIN.');
        }

        // The CSRF badge is checked before the user loader runs, so a forged post never reaches the admin API.
        return new SelfValidatingPassport(
            new UserBadge('driver-login', function () use ($request, $login, $pin): DriverUser {
                try {
                    $driver = $this->api->login($login, $pin, self::deviceId($request));
                } catch (DriverApiException $e) {
                    throw new CustomUserMessageAuthenticationException($e->getMessage(), [], 0, $e);
                }

                return new DriverUser($driver);
            }),
            [new CsrfTokenBadge(self::CSRF_ID, (string) $request->request->get('_csrf_token', ''))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->remove(SecurityRequestAttributes::LAST_USERNAME);

        return new RedirectResponse($this->urls->generate(self::HOME_ROUTE));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate(self::LOGIN_ROUTE));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $login = $this->urls->generate(self::LOGIN_ROUTE);
        // The page's fetch() would follow a redirect to the 200 login page and take it for success (the offline
        // queue would drop its taps): JSON calls get the 401 they already handle (app.js SWD.call).
        if (str_starts_with($request->getPathInfo(), '/driver/api/')) {
            return new JsonResponse(['error' => DriverApiException::SESSION_EXPIRED, 'code' => 'SESSION_EXPIRED', 'redirect' => $login], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($login);
    }

    /** One id per browser, shown in the admin's Driver API Log . */
    public static function deviceId(Request $request): string
    {
        $id = (string) $request->cookies->get(self::DEVICE_COOKIE, '');

        return preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? 'web-' . $id : 'web-unknown';
    }
}
