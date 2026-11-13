<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Security;

use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use App\Repository\UserRepository;
use App\Security\AccountLockManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class TokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly PersonalAccessTokenRepository $tokenRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly AccountLockManagerInterface $lockManager,
    ) {}

    public function supports(Request $request): ?bool
    {
        // Always active on the API firewall; missing/invalid credentials trigger onAuthenticationFailure
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with((string) $authHeader, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Missing or invalid Authorization header.');
        }

        $plaintext = substr((string) $authHeader, 7);
        $hash = hash('sha256', $plaintext);

        $pat = $this->tokenRepository->findByTokenHash($hash);

        if ($pat === null) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }

        if ($pat->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('Token has been revoked.');
        }

        if ($pat->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Token has expired.');
        }

        $pat->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        $userId = $pat->getUserId();

        return new SelfValidatingPassport(
            new UserBadge((string) $userId, function (string $userIdentifier) {
                $user = $this->userRepository->find((int) $userIdentifier);
                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException('User not found.');
                }
                // The api firewall is stateless with no user_checker, so account state must be
                // enforced here: a deactivated or locked user's PAT must stop authenticating.
                if ($user->getStatus() !== 'active') {
                    throw new CustomUserMessageAuthenticationException('Account is not active.');
                }
                // Lockout state lives in the auth-security-bundle satellite, read via the core port (null
                // → never locked when that bundle is absent) — FEATURE-144 / ADR-044.
                if ($this->lockManager->isLocked($user)) {
                    throw new CustomUserMessageAuthenticationException('Account is locked.');
                }
                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
