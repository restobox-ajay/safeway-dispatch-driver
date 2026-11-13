<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\DbConsoleSession;
use App\Repository\DbConsoleSessionRepository;
use App\Security\ConsoleCookie;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mints the credential for the phpLiteAdmin console (ADR-053).
 *
 * Two deliberate steps, not one: an admin must first ARM the console (a time-boxed kill-switch stored
 * in config), then OPEN it (which mints a token). Arming is what incident response reverses — setting
 * the deadline to 0 locks out every live console on its next request, however valid its token.
 *
 * Gated on ROLE_TECH_SUPPORT (ADR-050), not ROLE_ADMIN: raw database access is a maintainer power, and
 * that tier already carries a mandatory-2FA floor (ADR-050 / FEATURE-149), so nobody reaches this page
 * without a second factor.
 */
#[Route('/admin/db')]
#[IsGranted('ROLE_TECH_SUPPORT')]
class AdminDatabaseConsoleController extends AbstractController
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly EntityManagerInterface $em,
        private readonly DbConsoleSessionRepository $sessions,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route('', name: 'app_admin_db_console', methods: ['GET'])]
    public function console(): Response
    {
        $enabledUntil = $this->config->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0);

        return $this->render('admin/db_console/index.html.twig', [
            'enabledUntil'  => $enabledUntil,
            'isArmed'       => $enabledUntil > time(),
            'windowMinutes' => intdiv($this->windowSeconds(), 60),
        ]);
    }

    /**
     * Arm or disarm the console. Disarming is the kill-switch: the gateway checks the deadline before
     * anything else, so every open console dies on its next request.
     */
    #[Route('/enable', name: 'app_admin_db_enable', methods: ['POST'])]
    public function enable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('db_console_enable', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_db_console');
        }

        /** @var Admin $admin */
        $admin = $this->getUser();
        $disarm = $request->request->get('_disarm') !== null;

        if ($disarm) {
            $this->config->set(ConsoleCookie::ENABLED_UNTIL_KEY, '0');
            // Disarming is not merely "no new consoles": drop EVERY open one — every admin's, not just the
            // clicker's — so a suspected-stolen console cannot come back on the next re-arm (issue #48).
            $this->sessions->deleteAll();
            $this->audit($request, 'admin.db_console_disable');
            $this->addFlash('success', 'Database console disabled. Open sessions were revoked.');

            return $this->redirectToRoute('app_admin_db_console');
        }

        $this->config->set(
            ConsoleCookie::ENABLED_UNTIL_KEY,
            (string) (time() + $this->windowSeconds()),
        );
        $this->audit($request, 'admin.db_console_enable');
        $this->addFlash('success', sprintf('Database console armed for %d minutes.', intdiv($this->windowSeconds(), 60)));

        return $this->redirectToRoute('app_admin_db_console');
    }

    /**
     * Mint a token and hand it to the browser as a cookie scoped to the gateway, then bounce there.
     * Refuses when the console is not armed, so the two steps cannot be collapsed into one.
     *
     * A CSRF-checked POST (issue #41): it revokes the admin's other console, mints a token and writes an audit
     * row, and the panel cookie is SameSite=Lax, so as a GET any site could trigger it with a top-level link.
     */
    #[Route('/open', name: 'app_admin_db_open', methods: ['POST'])]
    public function open(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('db_console_open', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_db_console');
        }

        if ($this->config->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0) <= time()) {
            $this->addFlash('error', 'The database console is not enabled. Arm it first.');

            return $this->redirectToRoute('app_admin_db_console');
        }

        /** @var Admin $admin */
        $admin = $this->getUser();
        $token = ConsoleCookie::generateToken();
        $window = $this->windowSeconds();

        // One console per admin at a time: minting a new token retires any earlier one, so an
        // abandoned session on another machine cannot outlive this one.
        $this->sessions->deleteAllByAdminId((int) $admin->getId());

        $session = (new DbConsoleSession())
            ->setTokenHash(ConsoleCookie::hashToken($token))
            ->setAdminId((int) $admin->getId())
            ->setIpAddress($request->getClientIp() ?? '0.0.0.0')
            // In PHP's default timezone, like every other stored datetime here: the gateway reads and rewrites
            // this column with strtotime()/date(). A '@ts' object is UTC and Doctrine stores it as UTC
            // wall-clock time, which the gateway misread by the server's UTC offset (issue #40).
            ->setExpiresAt((new \DateTimeImmutable())->setTimestamp(time() + $window));

        $this->em->persist($session);
        $this->em->flush();

        $this->audit($request, 'admin.db_console_open');

        $response = new RedirectResponse('/db-admin.php');
        $response->headers->setCookie(Cookie::create(
            ConsoleCookie::COOKIE_NAME,
            $token,                 // the opaque token; only its hash is stored server-side
            0,                      // a session cookie — the row's expiry is the real bound
            '/db-admin.php',        // scoped to the gateway, so it is sent nowhere else
            null,
            $request->isSecure(),   // Secure when the request was, so local http still works
            true,                   // httpOnly
            false,
            Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    private function windowSeconds(): int
    {
        return ConsoleCookie::windowSeconds(
            $this->config->getInt(ConsoleCookie::WINDOW_MINUTES_KEY, ConsoleCookie::DEFAULT_WINDOW_MINUTES),
        );
    }

    private function audit(Request $request, string $action): void
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        $this->auditLogger->log(
            $admin->getEmail(),
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            $action,
            'success',
        );
    }
}
