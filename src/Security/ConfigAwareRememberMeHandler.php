<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

final class ConfigAwareRememberMeHandler implements RememberMeHandlerInterface
{
    private const COOKIE_NAME = 'REMEMBERME';
    private const DEFAULT_LIFETIME_DAYS = 30;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RequestStack $requestStack,
        private readonly ConfigService $configService,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        // FEATURE-091: cookie Domain attribute. Empty -> host-scoped (per-host
        // isolation); shared with the session cookie via the same env var.
        #[Autowire('%env(SESSION_COOKIE_DOMAIN)%')] private readonly string $cookieDomain = '',
    ) {}

    public function createRememberMeCookie(UserInterface $user): void
    {
        $days = $this->configService->getInt('remember_me.lifetime_days', self::DEFAULT_LIFETIME_DAYS);
        $expires = time() + ($days * 86400);
        $value = $this->computeHash($user, $expires);

        $this->writeCookie(new RememberMeDetails($user->getUserIdentifier(), $expires, $value));
    }

    public function consumeRememberMeCookie(RememberMeDetails $rememberMeDetails): UserInterface
    {
        if ($rememberMeDetails->getExpires() < time()) {
            throw new AuthenticationException('The remember me cookie has expired.');
        }

        $user = $this->userRepository->findByEmail($rememberMeDetails->getUserIdentifier());
        if (!$user instanceof UserInterface) {
            throw new AuthenticationException('User not found in remember me cookie.');
        }

        $expected = $this->computeHash($user, $rememberMeDetails->getExpires());
        if (!hash_equals($expected, $rememberMeDetails->getValue())) {
            throw new AuthenticationException('The remember me cookie has an invalid hash.');
        }

        // Rolling refresh: issue a new cookie with the current configured lifetime
        $this->createRememberMeCookie($user);

        return $user;
    }

    public function clearRememberMeCookie(): void
    {
        $this->writeCookie(null);
    }

    private function writeCookie(?RememberMeDetails $details): void
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request) {
            return;
        }

        $request->attributes->set(
            ResponseListener::COOKIE_ATTR_NAME,
            new Cookie(
                self::COOKIE_NAME,
                $details?->toString(),
                $details?->getExpires() ?? 1,
                '/',
                $this->cookieDomain !== '' ? $this->cookieDomain : null,
                $request->isSecure(),
                true,
                false,
                null,
            )
        );
    }

    private function computeHash(UserInterface $user, int $expires): string
    {
        $password = $user instanceof PasswordAuthenticatedUserInterface
            ? ($user->getPassword() ?? '')
            : '';

        // Fold in the sessions-invalidated marker so "logout everywhere" / force-logout /
        // deactivation invalidates every outstanding remember-me cookie for this user at once
        // (the bumped timestamp changes the expected HMAC, so old cookies fail hash_equals).
        $invalidatedMarker = $user instanceof User
            ? ($user->getSessionsInvalidatedAt()?->getTimestamp() ?? 0)
            : 0;

        return hash_hmac(
            'sha256',
            $user->getUserIdentifier() . ':' . $expires . ':' . $password . ':' . $invalidatedMarker,
            $this->secret,
        );
    }
}
