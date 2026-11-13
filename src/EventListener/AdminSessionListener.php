<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Admin;
use App\Entity\AdminSession;
use App\Repository\DbConsoleSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * FEATURE-123: admin-side mirror of {@see UserSessionListener}. Records each interactive admin login into
 * the dedicated admin_sessions cross-reference table, and removes the row on explicit logout, following
 * ADR-003 realm isolation.
 *
 * Kept deliberately separate and explicit from the user listener (review C8): the only cross-realm code
 * shared is the pure {@see InteractiveFirewallTrait} guard. There is no abstract base listener and no
 * shared table with a user_type discriminator — this listener writes its own admin table inline. Only
 * interactive, session-backed logins are recorded — never stateless admin-api (PAT) auth (FEATURE-097 /
 * AC4).
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
// Priority 1: run before Symfony's SessionLogoutListener (priority 0) invalidates the session, so the session id
// and the impersonation markers read below are still this session's.
#[AsEventListener(event: LogoutEvent::class, method: 'onLogout', priority: 1)]
class AdminSessionListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private DbConsoleSessionRepository $consoles,
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
        $sessionId = $request->getSession()->getId();
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $userAgent = substr($request->headers->get('User-Agent', ''), 0, 512);

        // Remove any stale row with the same session_id before inserting. Flush the removal immediately
        // so DELETE happens before INSERT — Doctrine's UoW processes inserts before deletes, which would
        // otherwise cause a UNIQ_admin_sessions_session_id violation if both were deferred to one flush().
        $existing = $this->em->getRepository(AdminSession::class)->findOneBy(['sessionId' => $sessionId]);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $adminSession = new AdminSession();
        $adminSession->setSessionId($sessionId);
        $adminSession->setAdminId((int) $admin->getId());
        $adminSession->setIp($ip);
        $adminSession->setUserAgent($userAgent);

        $this->em->persist($adminSession);
        $this->em->flush();
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $admin = $token->getUser();
        if (!$admin instanceof Admin) {
            return;
        }

        $session = $event->getRequest()->getSession();
        $sessionId = $session->getId();
        $adminSession = $this->em->getRepository(AdminSession::class)->findOneBy(['sessionId' => $sessionId]);
        if ($adminSession !== null) {
            $this->em->remove($adminSession);
            $this->em->flush();
        }

        // Logging out of the panel closes your DB console too: its cookie is IP-bound and survives the panel
        // session, so the next person at a shared browser would otherwise inherit full database access (#48).
        $this->consoles->deleteAllByAdminId((int) $admin->getId());

        // While impersonating, the token holds the target admin, but the person at this browser is the
        // impersonator, and theirs is the console cookie on it.
        $impersonatorEmail = $session->get('_impersonating_admin_by');
        if (is_string($impersonatorEmail) && $impersonatorEmail !== '') {
            $impersonator = $this->em->getRepository(Admin::class)->findOneBy(['email' => $impersonatorEmail]);
            if ($impersonator !== null) {
                $this->consoles->deleteAllByAdminId((int) $impersonator->getId());
            }
        }
    }
}
