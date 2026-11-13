<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Admin;
use App\Htaccess\HtaccessLockActor;
use App\Htaccess\HtaccessLockForbiddenException;
use App\Htaccess\HtaccessLockGate;
use App\Htaccess\HtaccessLockOutcome;
use App\Htaccess\HtaccessLockResult;
use App\Htaccess\HtaccessLockSelfTestReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Htaccess Lock (ADR-059) over the admin REST API — TECH SUPPORT ONLY, exactly like the admin page
 * (not even a superadmin). A thin adapter (ADR-062): it decodes JSON, calls {@see HtaccessLockGate} — the
 * same gate the admin page and the recovery command use, which alone validates, guards against lockout,
 * writes, audits and fires the change event — and encodes the {@see HtaccessLockResult}. Nothing here
 * touches .htaccess, the validator or the audit log.
 *
 * CIDR entries and paths contain slashes, so the two DELETE calls take the value as a query parameter
 * (`?ip=203.0.113.0/24`, `?path=/health`) rather than a path segment, which web servers mangle.
 */
#[IsGranted('ROLE_TECH_SUPPORT')]
#[Route('/admin-api/htaccess-lock')]
final class AdminApiHtaccessLockController extends AbstractController
{
    public function __construct(private readonly HtaccessLockGate $gate)
    {
    }

    #[Route('', name: 'app_api_admin_htaccess_lock_show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => new JsonResponse($this->serializeLock($request)));
    }

    #[Route('', name: 'app_api_admin_htaccess_lock_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $data = $this->body($request);
            if ($data instanceof JsonResponse) {
                return $data;
            }

            $fields = [];
            $errors = [];
            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'ips':
                    case 'exempt_paths':
                        if (!\is_array($value) || !array_is_list($value) || array_filter($value, static fn (mixed $v): bool => !\is_string($v) || preg_match('/[\r\n]/', $v) === 1) !== []) {
                            $errors[] = sprintf('"%s" must be an array of single-line strings.', $key);
                        } else {
                            $fields[$key] = implode("\n", $value);
                        }
                        break;
                    case 'status_code':
                        $fields[$key] = \is_int($value) ? (string) $value : '';
                        break;
                    case 'error_file':
                        if (!\is_string($value)) {
                            $errors[] = '"error_file" must be a string ("" for none).';
                        } else {
                            $fields[$key] = $value;
                        }
                        break;
                    case 'enabled':
                        $errors[] = '"enabled" cannot be patched; use POST /admin-api/htaccess-lock/enable or /disable.';
                        break;
                    default:
                        $errors[] = sprintf('Unknown field "%s".', (string) $key);
                }
            }
            if ($fields === [] && $errors === []) {
                $errors[] = 'Send at least one of: ips, exempt_paths, status_code, error_file.';
            }
            if ($errors !== []) {
                return $this->rejected($errors);
            }

            return $this->respond($request, $this->gate->update($this->actor($request), $fields));
        });
    }

    #[Route('/enable', name: 'app_api_admin_htaccess_lock_enable', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->respond($request, $this->gate->enable($this->actor($request))));
    }

    #[Route('/disable', name: 'app_api_admin_htaccess_lock_disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->respond($request, $this->gate->disable($this->actor($request))));
    }

    #[Route('/ips', name: 'app_api_admin_htaccess_lock_ips_list', methods: ['GET'])]
    public function listIps(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => new JsonResponse(['data' => $this->gate->listIps($this->actor($request))]));
    }

    #[Route('/ips', name: 'app_api_admin_htaccess_lock_ips_add', methods: ['POST'])]
    public function addIp(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $value = $this->stringField($request, 'ip');

            return $value instanceof JsonResponse ? $value : $this->respondList($request, $this->gate->addIp($this->actor($request), $value), 'ips', Response::HTTP_CREATED);
        });
    }

    #[Route('/ips', name: 'app_api_admin_htaccess_lock_ips_remove', methods: ['DELETE'])]
    public function removeIp(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->respondList($request, $this->gate->removeIp($this->actor($request), (string) $request->query->get('ip', '')), 'ips'));
    }

    #[Route('/exempt-paths', name: 'app_api_admin_htaccess_lock_exempt_list', methods: ['GET'])]
    public function listExemptPaths(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => new JsonResponse(['data' => $this->gate->listExemptPaths($this->actor($request))]));
    }

    #[Route('/exempt-paths', name: 'app_api_admin_htaccess_lock_exempt_add', methods: ['POST'])]
    public function addExemptPath(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $value = $this->stringField($request, 'path');

            return $value instanceof JsonResponse ? $value : $this->respondList($request, $this->gate->addExemptPath($this->actor($request), $value), 'exempt', Response::HTTP_CREATED);
        });
    }

    #[Route('/exempt-paths', name: 'app_api_admin_htaccess_lock_exempt_remove', methods: ['DELETE'])]
    public function removeExemptPath(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->respondList($request, $this->gate->removeExemptPath($this->actor($request), (string) $request->query->get('path', '')), 'exempt'));
    }

    #[Route('/self-test', name: 'app_api_admin_htaccess_lock_self_test_last', methods: ['GET'])]
    public function lastSelfTest(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $report = $this->gate->lastTest($this->actor($request));

            return $report === null
                ? new JsonResponse(['error' => 'The self-test has never been run.'], Response::HTTP_NOT_FOUND)
                : new JsonResponse($this->serializeReport($report));
        });
    }

    #[Route('/self-test', name: 'app_api_admin_htaccess_lock_self_test_run', methods: ['POST'])]
    public function runSelfTest(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $scheme = $request->getScheme();
            $port = (int) ($request->getPort() ?? ($scheme === 'https' ? 443 : 80));

            return new JsonResponse($this->serializeReport($this->gate->runSelfTest($this->actor($request), $scheme, $request->getHost(), $port)));
        });
    }

    // ---- encoding ------------------------------------------------------------------------------------------

    /** The gate's own refusal (defence in depth behind #[IsGranted]) is the same 403 the firewall gives. */
    private function guarded(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (HtaccessLockForbiddenException) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }
    }

    /** A mutation's outcome: the new lock state on success, the gate's reason otherwise. */
    private function respond(Request $request, HtaccessLockResult $result, int $status = Response::HTTP_OK): JsonResponse
    {
        return $result->isApplied() ? new JsonResponse($this->serializeLock($request), $status) : $this->failure($result);
    }

    /** As {@see respond()} for the list endpoints, which answer with the resulting list. */
    private function respondList(Request $request, HtaccessLockResult $result, string $which, int $status = Response::HTTP_OK): JsonResponse
    {
        if (!$result->isApplied()) {
            return $this->failure($result);
        }

        return new JsonResponse(['data' => $which === 'ips' ? $result->settings->ips : $result->settings->exemptPaths], $status);
    }

    private function failure(HtaccessLockResult $result): JsonResponse
    {
        return match ($result->outcome) {
            HtaccessLockOutcome::Rejected => $this->rejected($result->errors),
            HtaccessLockOutcome::Conflict => new JsonResponse(['error' => $result->errors[0]], Response::HTTP_CONFLICT),
            HtaccessLockOutcome::NotFound => new JsonResponse(['error' => $result->errors[0]], Response::HTTP_NOT_FOUND),
            HtaccessLockOutcome::WriteFailed => new JsonResponse(['error' => $result->errors[0]], Response::HTTP_INTERNAL_SERVER_ERROR),
            HtaccessLockOutcome::Applied => throw new \LogicException('An applied result is not a failure.'),
        };
    }

    /** @param list<string> $errors */
    private function rejected(array $errors): JsonResponse
    {
        return new JsonResponse(['error' => $errors[0], 'errors' => array_values($errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string,mixed>|JsonResponse the decoded JSON object, or the 422 to send */
    private function body(Request $request): array|JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) && !array_is_list($data) && $data !== [] ? $data : $this->rejected(['The request body must be a non-empty JSON object.']);
    }

    private function stringField(Request $request, string $name): string|JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $value = \is_array($data) ? ($data[$name] ?? null) : null;

        return \is_string($value) ? $value : $this->rejected([sprintf('"%s" is required and must be a string.', $name)]);
    }

    /** @return array<string,mixed> */
    private function serializeLock(Request $request): array
    {
        $view = $this->gate->view($this->actor($request));
        $file = $view->fileState;

        return [
            'enabled' => $view->settings->enabled,
            'status_code' => $view->settings->statusCode,
            'error_file' => $view->settings->errorFile,
            'ips' => $view->settings->ips,
            'exempt_paths' => $view->settings->exemptPaths,
            'file' => [
                'exists' => $file['exists'],
                'writable' => $file['writable'],
                'has_block' => $file['hasBlock'],
                'in_sync' => $file['inSync'],
                'error' => $file['error'],
            ],
            'your_ip' => $view->yourIp,
        ];
    }

    /** @return array<string,mixed> */
    private function serializeReport(HtaccessLockSelfTestReport $report): array
    {
        return [
            'passed' => $report->passed(),
            'ran_at' => (new \DateTimeImmutable('@' . $report->ranAt))->format(\DateTimeInterface::ATOM),
            'steps' => array_map(static fn (array $s): array => ['label' => $s['label'], 'passed' => $s['passed'], 'detail' => $s['detail']], $report->steps),
        ];
    }

    private function actor(Request $request): HtaccessLockActor
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        return HtaccessLockActor::admin($admin->getEmail(), $request->getClientIp() ?? '', $this->isGranted('ROLE_TECH_SUPPORT'));
    }
}
