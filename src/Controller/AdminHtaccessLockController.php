<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Admin;
use App\Htaccess\HtaccessLockActor;
use App\Htaccess\HtaccessLockGate;
use App\Htaccess\HtaccessLockOutcome;
use App\Htaccess\HtaccessLockSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Htaccess Lock (ADR-059): an IP-whitelist-only lock enforced by the web server itself, by editing a
 * managed block of `.htaccess`. It runs before PHP, so it covers the whole site (static files, the
 * admin panel, the DB-console gateway) rather than only the login form like the auth-ip-whitelist bundle.
 *
 * Gated on ROLE_TECH_SUPPORT, not ROLE_SUPER_ADMIN: rewriting the server's access rules is a maintainer
 * power, and a client superadmin must not be able to lock the maintainers (or themselves) out. That tier
 * already carries the mandatory-2FA floor (ADR-050).
 *
 * A thin adapter (ADR-062): it only turns the form into a call on {@see HtaccessLockGate} — the one gate
 * the tech-support API and the recovery command share — and renders what comes back. It validates,
 * audits and writes nothing itself.
 */
#[Route('/admin/htaccess-lock')]
#[IsGranted('ROLE_TECH_SUPPORT')]
class AdminHtaccessLockController extends AbstractController
{
    public function __construct(private readonly HtaccessLockGate $gate)
    {
    }

    #[Route('', name: 'app_admin_htaccess_lock', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderPage($request, $this->formValues($this->gate->view($this->actor($request))->settings), []);
    }

    #[Route('/save', name: 'app_admin_htaccess_lock_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('htaccess_lock_save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_htaccess_lock');
        }

        $values = [
            'enabled' => $request->request->get('enabled') === '1' ? '1' : '0',
            'ips' => (string) $request->request->get('ips', ''),
            'exempt_paths' => (string) $request->request->get('exempt_paths', ''),
            'status_code' => (string) $request->request->get('status_code', ''),
            'error_file' => (string) $request->request->get('error_file', ''),
        ];

        $result = $this->gate->update($this->actor($request), [
            'enabled' => $values['enabled'] === '1',
            'ips' => $values['ips'],
            'exempt_paths' => $values['exempt_paths'],
            'status_code' => $values['status_code'],
            'error_file' => $values['error_file'],
        ]);

        if (!$result->isApplied()) {
            return $this->renderPage(
                $request,
                $values,
                $result->errors,
                $result->outcome === HtaccessLockOutcome::WriteFailed ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->addFlash('success', $result->settings->enabled
            ? 'Htaccess Lock saved and active. Allow a couple of seconds for the web server to pick up the change.'
            : 'Htaccess Lock is off; its block was removed from .htaccess.');

        return $this->redirectToRoute('app_admin_htaccess_lock');
    }

    #[Route('/test', name: 'app_admin_htaccess_lock_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('htaccess_lock_test', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_htaccess_lock');
        }

        $scheme = $request->getScheme();
        $port = (int) ($request->getPort() ?? ($scheme === 'https' ? 443 : 80));

        $report = $this->gate->runSelfTest($this->actor($request), $scheme, $request->getHost(), $port);

        $this->addFlash($report->passed() ? 'success' : 'error', $report->passed()
            ? 'Self-test passed: this server enforces the Htaccess Lock.'
            : 'Self-test FAILED — do not rely on the lock until every step passes. See the results below.');

        return $this->redirectToRoute('app_admin_htaccess_lock');
    }

    /**
     * @param array<string,string> $values
     * @param list<string>         $errors
     */
    private function renderPage(Request $request, array $values, array $errors, int $status = Response::HTTP_OK): Response
    {
        $view = $this->gate->view($this->actor($request));

        return $this->render('admin/htaccess_lock/index.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'fileState' => $view->fileState,
            'filePath' => $view->filePath,
            'currentIp' => $view->yourIp,
            'lastTest' => $view->lastTest,
        ], new Response('', $status));
    }

    /** @return array<string,string> */
    private function formValues(HtaccessLockSettings $settings): array
    {
        return [
            'enabled' => $settings->enabled ? '1' : '0',
            'ips' => implode("\n", $settings->ips),
            'exempt_paths' => implode("\n", $settings->exemptPaths),
            'status_code' => (string) $settings->statusCode,
            'error_file' => $settings->errorFile,
        ];
    }

    private function actor(Request $request): HtaccessLockActor
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        return HtaccessLockActor::admin($admin->getEmail(), $request->getClientIp() ?? '', $this->isGranted('ROLE_TECH_SUPPORT'));
    }
}
