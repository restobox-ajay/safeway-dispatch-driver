<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConfigFieldValidator;
use App\Config\ConfigPageRegistry;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminConfigController extends AbstractController
{
    public function __construct(
        private readonly ConfigPageRegistry $registry,
        private readonly ConfigService $configService,
        private readonly ConfigFieldValidator $validator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Field types whose values are safe to put in the audit log. Free text (e.g. webhook URLs) may embed secrets. */
    private const AUDITABLE_VALUE_TYPES = ['bool', 'int', 'enum', 'ip_list'];

    #[Route('/admin/config', name: 'app_admin_config', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderConfig();
    }

    #[Route('/admin/config/{slug}', name: 'app_admin_config_save', methods: ['POST'])]
    public function save(string $slug, Request $request): Response
    {
        // Every page here governs security (2FA enforcement, IP lists, sessions, registration, retention, webhooks):
        // only a superadmin changes it. A plain admin could otherwise switch off the superadmins' own 2FA or lock
        // them out by IP (audit #2). Plain admins still see the settings, read-only.
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $provider = $this->registry->getBySlug($slug);
        if (!$provider) {
            throw $this->createNotFoundException("Config page '$slug' not found.");
        }

        if (!$this->isCsrfTokenValid('admin_config_save_' . $slug, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Form field names use fields[config.key] to preserve dots (PHP converts top-level dots to underscores)
        $submitted = $request->request->all('fields') ?: [];

        // First pass: coerce + validate the submitted fields against their declared
        // type/constraints. A bool checkbox is always resolvable (present = on = '1',
        // absent = off = '0'); any other field is processed only when it was actually
        // submitted, so a partial POST never clobbers an untouched value with ''.
        // The save is atomic per page — if ANY submitted field is invalid, nothing is
        // persisted (a half-valid form would leave config in a mixed state).
        $values = [];
        $errors = [];
        foreach ($provider->getFields() as $key => $field) {
            if ($field['type'] === 'bool') {
                $value = array_key_exists($key, $submitted) ? '1' : '0';
            } elseif (array_key_exists($key, $submitted)) {
                $value = (string) $submitted[$key];
            } else {
                continue;
            }
            $values[$key] = $value;

            $error = $this->validator->validate($field, $value, $request->getClientIp());
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        if ($errors !== []) {
            return $this->renderConfig($errors, $slug, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // What actually changes, for the audit row (issue #12): this page holds 2FA enforcement, IP whitelists,
        // rate limits and the SSRF guard, so who changed what, when, must be on record.
        $fields = $provider->getFields();
        $changes = [];
        foreach ($values as $key => $value) {
            $old = $this->configService->getString($key, (string) ($fields[$key]['default'] ?? ''));
            if ($old === $value) {
                continue;
            }
            $changes[] = \in_array($fields[$key]['type'] ?? 'text', self::AUDITABLE_VALUE_TYPES, true)
                ? sprintf('%s: %s -> %s', $key, $this->auditValue($old), $this->auditValue($value))
                : sprintf('%s: changed (value not logged)', $key);
        }

        // The writes and their audit row commit together (C10).
        $this->em->wrapInTransaction(function () use ($values, $changes, $slug, $request): void {
            foreach ($values as $key => $value) {
                $this->configService->set($key, $value);
            }
            if ($changes !== []) {
                $this->auditLogger->logDeferred(
                    $this->getUser()?->getUserIdentifier() ?? 'unknown',
                    'admin',
                    $request->getClientIp() ?? '0.0.0.0',
                    'admin.config_update',
                    'success',
                    sprintf('page=%s; %s', $slug, implode('; ', $changes)),
                );
            }
        });

        $this->addFlash('success', 'Configuration saved.');

        return $this->redirectToRoute('app_admin_config');
    }

    private function auditValue(string $value): string
    {
        // Not truncated: a cut could hide a change made after the cut (e.g. in a long IP list). The column is TEXT.
        return $value === '' ? '(empty)' : $value;
    }

    /**
     * Builds and renders the config index. On a failed save, $errors carries per-key
     * messages and $overrideValues redisplays the admin's rejected input for $overrideSlug.
     *
     * @param array<string, string> $errors         config key => error message
     * @param array<string, string> $overrideValues config key => submitted value (for $overrideSlug)
     */
    private function renderConfig(
        array $errors = [],
        ?string $overrideSlug = null,
        array $overrideValues = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $pages = [];
        foreach ($this->registry->getAll() as $provider) {
            $isOverridden = $overrideSlug !== null && $provider->getSlug() === $overrideSlug;
            $values = [];
            foreach ($provider->getFields() as $key => $field) {
                if ($isOverridden && array_key_exists($key, $overrideValues)) {
                    $values[$key] = $overrideValues[$key];
                } else {
                    $values[$key] = $this->configService->getString($key, (string) ($field['default'] ?? ''));
                }
            }
            $pages[] = [
                'provider' => $provider,
                'values'   => $values,
            ];
        }

        return $this->render('admin/config/index.html.twig', [
            'pages'  => $pages,
            'errors' => $errors,
        ], new Response('', $status));
    }
}
