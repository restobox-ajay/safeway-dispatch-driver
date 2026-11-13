<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\Controller;

use App\Entity\Admin;
use App\Entity\AdminSession;
use App\Repository\AdminSessionRepository;
use App\Repository\UserSessionRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The /impersonate/* flow, owned by auth-impersonate-bundle (FEATURE-142 / ADR-041). Routes are
 * imported ONLY when the bundle is registered (App\Kernel::configureRoutes), so with the bundle
 * absent they 404 (AC3). Behaviour is unchanged from the pre-extraction core controller — only the
 * namespace moved; ADR-014's custom session-handoff and the C18/C26 hardening are preserved verbatim.
 */
class ImpersonationController extends AbstractController
{
    #[Route('/impersonate/start', name: 'app_impersonate_start', methods: ['GET'])]
    public function start(): Response
    {
        // ImpersonationAuthenticator handles authentication before this action runs.
        // If we reach here without being redirected, authentication succeeded — redirect to dashboard.
        return $this->redirectToRoute('app_dashboard');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/impersonate/exit', name: 'app_impersonate_exit', methods: ['POST'])]
    public function exitImpersonation(
        Request $request,
        AuditLogger $auditLogger,
        AdminSessionRepository $adminSessionRepository,
        UserSessionRepository $userSessionRepository,
        TokenStorageInterface $tokenStorage,
        EntityManagerInterface $em,
        #[Autowire(service: 'security.user.provider.concrete.app_admins')]
        UserProviderInterface $adminProvider,
    ): Response {
        $session = $request->getSession();

        // No-op when no impersonation is in progress: don't log a garbage audit row or tear
        // down the session (which would log the caller out). Checked before CSRF because the
        // no-op path performs no state change (review C26 / FEATURE-118).
        if (!$session->has('_impersonating_as')) {
            return $this->redirectToRoute('app_dashboard');
        }

        if (!$this->isCsrfTokenValid('impersonate_exit', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $adminEmail  = (string) $session->get('_impersonating_by', 'unknown');
        $targetEmail = (string) $session->get('_impersonating_as', 'unknown');

        $auditLogger->log(
            $adminEmail,
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.impersonate_exit',
            'success',
            $targetEmail
        );

        $session->remove('_impersonating_as');
        $session->remove('_impersonating_by');
        // Sign the browser out of the impersonated user's account (issue #64). Removing `_security_user` alone is
        // not enough: this request runs on the user firewall, so ContextListener holds the user's token and would
        // write it straight back into the session at response time. Clearing the token makes it remove the key
        // instead. The user_sessions row the impersonation created for this session goes too.
        $tokenStorage->setToken(null);
        $session->remove('_security_user');
        $impersonatedSession = $userSessionRepository->findBySessionId($session->getId());
        if ($impersonatedSession !== null) {
            $em->remove($impersonatedSession);
            $em->flush();
        }

        // FEATURE-123: starting a user impersonation logs in on the user firewall, which MIGRATES the
        // PHP session id (session-fixation protection). That orphans the admin's admin_sessions row
        // (keyed on the pre-migration session id), so on returning to the admin firewall the
        // AdminSessionRequestListener would find no row for the current session and log the admin out.
        // Re-establish a session row for the resumed admin on the current session id so admin session
        // tracking + "logout everywhere" keep working across an impersonation round-trip. (Admin-side
        // impersonation forges a token without a login, so it never migrates the session and needs no
        // equivalent here.)
        if ($adminEmail !== '' && $adminEmail !== 'unknown') {
            try {
                $admin = $adminProvider->loadUserByIdentifier($adminEmail);
                if ($admin instanceof Admin
                    && $adminSessionRepository->findBySessionId($session->getId()) === null
                ) {
                    $adminSession = new AdminSession();
                    $adminSession->setSessionId($session->getId());
                    $adminSession->setAdminId((int) $admin->getId());
                    $adminSession->setIp($request->getClientIp() ?? '0.0.0.0');
                    $adminSession->setUserAgent(substr($request->headers->get('User-Agent', ''), 0, 512));
                    $em->persist($adminSession);
                    $em->flush();
                }
            } catch (UserNotFoundException) {
                // Admin was deleted mid-impersonation — nothing to re-establish; the admin firewall
                // will simply fail to re-authenticate on the next request.
            }
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/impersonate-admin-exit', name: 'app_admin_impersonate_admin_exit', methods: ['POST'])]
    public function exitAdminImpersonation(
        Request $request,
        TokenStorageInterface $tokenStorage,
        AuditLogger $auditLogger,
        #[Autowire(service: 'security.user.provider.concrete.app_admins')]
        UserProviderInterface $adminProvider,
    ): Response {
        $session = $request->getSession();

        // No-op when no admin impersonation is in progress: don't log a garbage audit row or
        // restore/tear down anything. Checked before CSRF because the no-op path performs no
        // state change (review C26 / FEATURE-118).
        if (!$session->has('_impersonating_admin_as')) {
            return $this->redirectToRoute('app_admin_superadmin_admins');
        }

        if (!$this->isCsrfTokenValid('admin_impersonate_exit', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $superadminEmail = (string) $session->get('_impersonating_admin_by', 'unknown');
        $targetEmail     = (string) $session->get('_impersonating_admin_as', 'unknown');

        $session->remove('_impersonating_admin_as');
        $session->remove('_impersonating_admin_by');

        $auditLogger->log(
            $superadminEmail,
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.impersonate_admin_exit',
            'success',
            $targetEmail
        );

        // Restore the original superadmin by identifier — never by unserializing a stored
        // token (review C18 / FEATURE-134). Load the Admin via the admin user provider and
        // mint a fresh token; ContextListener persists it into _security_admin at response
        // time. Fail closed on any problem: drop the admin token so no stale/impersonated
        // session survives, and send the caller to a clean admin login.
        $restored = null;
        if ($superadminEmail !== '' && $superadminEmail !== 'unknown') {
            try {
                $admin = $adminProvider->loadUserByIdentifier($superadminEmail);
                if ($admin instanceof Admin && $admin->isActive()) {
                    $restored = new PostAuthenticationToken($admin, 'admin', $admin->getRoles());
                }
            } catch (UserNotFoundException) {
                // Superadmin was deleted mid-impersonation — fail closed below.
            }
        }

        if ($restored === null) {
            $tokenStorage->setToken(null);
            $session->remove('_security_admin');

            return $this->redirectToRoute('app_admin_login');
        }

        $tokenStorage->setToken($restored);

        return $this->redirectToRoute('app_admin_superadmin_admins');
    }
}
