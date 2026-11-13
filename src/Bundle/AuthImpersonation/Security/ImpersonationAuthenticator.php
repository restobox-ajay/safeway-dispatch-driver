<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\Security;

use App\Repository\UserRepository;
use App\Service\ConfigService;
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
 * The `user` firewall's impersonation authenticator, owned by auth-impersonate-bundle (FEATURE-142 /
 * ADR-041). Wired via the stable core alias `app.impersonation_authenticator` (see security.yaml),
 * which core defaults to {@see \App\Security\NullImpersonationAuthenticator} and this bundle's compiler
 * pass re-aliases to this class. Preserves ADR-014's custom session-handoff: the admin-side trigger
 * (kept in core) writes `_impersonation_request` to the shared PHP session, then redirects to
 * app_impersonate_start where this authenticator reads and validates the payload and logs in as the
 * target user. Behaviour is unchanged from the pre-extraction core class — only the namespace moved.
 */
final class ImpersonationAuthenticator extends AbstractAuthenticator
{
    /** Request attribute carrying the impersonating admin's email from authenticate() to onAuthenticationSuccess(). */
    private const ADMIN_ATTRIBUTE = '_impersonation_admin_email';

    /** The "Allow Impersonation" toggle on /admin/config/impersonate (default on). */
    public const ENABLED_KEY = 'impersonate.enabled';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ConfigService $config,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_impersonate_start';
    }

    public function authenticate(Request $request): Passport
    {
        if ($request->hasSession() && !$request->getSession()->isStarted()) {
            $request->getSession()->start();
        }

        $session = $request->getSession();

        // "Allow Impersonation" (issue #9) — also refuses a request queued before the toggle was switched off.
        if (!$this->config->getBool(self::ENABLED_KEY, true)) {
            $session->remove('_impersonation_request');
            throw new CustomUserMessageAuthenticationException('Impersonation is disabled.');
        }

        $payload = $session->get('_impersonation_request');

        if (!is_array($payload) || !isset($payload['userId'], $payload['adminEmail'])) {
            throw new CustomUserMessageAuthenticationException('No impersonation request found. Please start from the admin panel.');
        }

        $userId     = (int) $payload['userId'];
        $adminEmail = (string) $payload['adminEmail'];

        $user = $this->userRepository->find($userId);
        if ($user === null) {
            throw new CustomUserMessageAuthenticationException('Impersonation target user not found.');
        }

        // One-shot: the request is consumed whatever happens next. The "impersonating" markers are NOT written
        // here — the user checker still runs after this and can refuse the target (locked, inactive,
        // unverified). Written now, a refused impersonation would leave markers that skip 2FA / password expiry
        // for that account later in the same session (issue #16). They are written in onAuthenticationSuccess().
        $session->remove('_impersonation_request');
        $request->attributes->set(self::ADMIN_ATTRIBUTE, $adminEmail);

        return new SelfValidatingPassport(new UserBadge((string) $user->getEmail(), function (string $email) {
            return $this->userRepository->findByEmail($email);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $session = $request->getSession();
        $session->set('_impersonating_as', $token->getUserIdentifier());
        $session->set('_impersonating_by', (string) $request->attributes->get(self::ADMIN_ATTRIBUTE, 'unknown'));

        return new RedirectResponse('/dashboard');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
        return new RedirectResponse('/admin/login');
    }
}
