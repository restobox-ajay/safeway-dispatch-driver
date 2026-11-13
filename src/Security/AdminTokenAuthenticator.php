<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AdminAccessTokenRepository;
use App\Repository\AdminRepository;
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

/**
 * Authenticates the admin REST API (/admin-api/*) against Admin entities via bearer tokens.
 * Mirrors TokenAuthenticator (the user-side PAT authenticator), but loads Admins — so the
 * admin API can only ever be driven by a real Admin identity, never a User with a role.
 */
final class AdminTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly AdminAccessTokenRepository $tokenRepository,
        private readonly AdminRepository $adminRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function supports(Request $request): ?bool
    {
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

        $token = $this->tokenRepository->findByTokenHash($hash);

        if ($token === null) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }

        if ($token->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('Token has been revoked.');
        }

        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Token has expired.');
        }

        $token->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        $adminId = $token->getAdminId();

        return new SelfValidatingPassport(
            new UserBadge((string) $adminId, function (string $identifier) {
                $admin = $this->adminRepository->find((int) $identifier);
                if ($admin === null) {
                    throw new CustomUserMessageAuthenticationException('Admin not found.');
                }
                // The admin_api firewall is stateless with no user_checker, so account state
                // must be enforced here: a deactivated admin's token must stop authenticating.
                if (!$admin->isActive()) {
                    throw new CustomUserMessageAuthenticationException('Account is not active.');
                }
                return $admin;
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
