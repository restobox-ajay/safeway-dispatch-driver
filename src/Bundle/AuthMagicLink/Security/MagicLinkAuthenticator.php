<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Security;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Bundle\AuthMagicLink\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use App\Security\IpWhitelistedAuthenticatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * Passwordless (magic-link) authenticator on the `user` firewall — owned by auth-magic-link-bundle
 * (FEATURE-140). Implements {@see IpWhitelistedAuthenticatorInterface} so
 * {@see \App\Bundle\AuthIpWhitelist\EventListener\IpWhitelistListener} enforces the IP whitelist on
 * magic-link logins too (review C20 / FEATURE-113) without core referencing either bundle class directly.
 */
final class MagicLinkAuthenticator extends AbstractAuthenticator implements IpWhitelistedAuthenticatorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_magic_link_verify';
    }

    public function authenticate(Request $request): Passport
    {
        // Start the session explicitly so SessionAuthenticationStrategy can migrate it.
        // On GET requests (unlike form login POST) the session is not yet started,
        // which means the session ID would not be migrated and the token would not
        // be saved to a cookie — leaving the user unauthenticated after the redirect.
        if ($request->hasSession() && !$request->getSession()->isStarted()) {
            $request->getSession()->start();
        }

        $plaintext = (string) $request->query->get('token', '');
        $tokenHash = hash('sha256', $plaintext);

        /** @var MagicLinkToken|null $magicToken */
        $magicToken = $this->em->getRepository(MagicLinkToken::class)
            ->findOneBy(['tokenHash' => $tokenHash]);

        if ($magicToken === null) {
            throw new CustomUserMessageAuthenticationException('This magic link is invalid.');
        }

        if ($magicToken->isExpired()) {
            throw new CustomUserMessageAuthenticationException('This magic link has expired. Please request a new one.');
        }

        if ($magicToken->isUsed()) {
            throw new CustomUserMessageAuthenticationException('This magic link has already been used.');
        }

        // The checks above only pick the error message; whether this request may use the link is decided by
        // the database. A concurrent verification of the same link may have consumed it since we read it.
        $repository = $this->em->getRepository(MagicLinkToken::class);
        \assert($repository instanceof MagicLinkTokenRepository);
        if (!$repository->consume($tokenHash, new \DateTimeImmutable())) {
            throw new CustomUserMessageAuthenticationException('This magic link has already been used.');
        }

        return new SelfValidatingPassport(new UserBadge($magicToken->getEmail(), function (string $email) {
            return $this->userRepository->findByEmail($email);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Return null so the controller action runs and the session is saved
        // through the normal response pipeline — direct RedirectResponse here
        // causes session persistence issues in the test environment.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('magic_link_error', $exception->getMessage());
        return new RedirectResponse('/magic-link');
    }
}
