<?php

declare(strict_types=1);

namespace App\Htaccess;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * THE one gate to the Htaccess Lock (ADR-062). Every read and every change — the admin page, the tech-support
 * API, the recovery console command — goes through this class and nothing else, so authorization, validation,
 * the self-lockout guard, the .htaccess write, the audit row and the {@see HtaccessLockChangedEvent} hook each
 * happen in exactly one place and cannot drift between adapters. The adapters only translate their own
 * transport (form fields, JSON, argv) in and the {@see HtaccessLockResult} out.
 *
 * Every change is expressed as "derive the complete new raw policy from the current one" and funnelled through
 * {@see mutate()}; adding one IP is therefore validated exactly like saving the whole form (canonical form,
 * /0 refused, lockout guard against the caller's own IP, …). An architecture test
 * (HtaccessLockGateArchitectureTest) fails the build if any code outside src/Htaccess/ reaches past this class.
 */
final class HtaccessLockGate
{
    public const ACTION_UPDATE = 'admin.htaccess_lock_update';
    public const ACTION_ENABLE = 'admin.htaccess_lock_enable';
    public const ACTION_DISABLE = 'admin.htaccess_lock_disable';
    public const ACTION_IP_ADD = 'admin.htaccess_lock_ip_add';
    public const ACTION_IP_REMOVE = 'admin.htaccess_lock_ip_remove';
    public const ACTION_EXEMPT_ADD = 'admin.htaccess_lock_exempt_add';
    public const ACTION_EXEMPT_REMOVE = 'admin.htaccess_lock_exempt_remove';
    public const ACTION_TEST = 'admin.htaccess_lock_test';

    public function __construct(
        private readonly HtaccessLockManager $manager,
        private readonly HtaccessLockValidator $validator,
        private readonly HtaccessLockSelfTest $selfTest,
        private readonly AuditLogger $auditLogger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly HtaccessLockMutex $mutex,
    ) {
    }

    // ---- reads ---------------------------------------------------------------------------------------------

    public function view(HtaccessLockActor $actor): HtaccessLockView
    {
        $this->authorize($actor);

        return new HtaccessLockView(
            $this->manager->settings(),
            $this->manager->fileState(),
            $this->manager->file()->path(),
            $this->manager->lastTest(),
            $actor->console ? null : $actor->ip,
        );
    }

    /** @return list<string> */
    public function listIps(HtaccessLockActor $actor): array
    {
        $this->authorize($actor);

        return $this->manager->settings()->ips;
    }

    /** @return list<string> */
    public function listExemptPaths(HtaccessLockActor $actor): array
    {
        $this->authorize($actor);

        return $this->manager->settings()->exemptPaths;
    }

    public function lastTest(HtaccessLockActor $actor): ?HtaccessLockSelfTestReport
    {
        $this->authorize($actor);

        return $this->manager->lastTest();
    }

    // ---- changes -------------------------------------------------------------------------------------------

    /**
     * Change any subset of the policy; fields that are absent keep their current value. The admin form sends
     * all of them; the API's PATCH sends what the caller named.
     *
     * @param array{enabled?:bool,ips?:string,exempt_paths?:string,status_code?:string,error_file?:string} $fields
     *        ips / exempt_paths are newline-separated, exactly as typed into the admin form
     */
    public function update(HtaccessLockActor $actor, array $fields): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_UPDATE, static fn (array $raw): array => [
            'raw' => array_replace($raw, $fields),
            'detail' => null,
        ]);
    }

    /** Turn the lock on (validated, incl. the lockout guard) — the saved whitelist is used as it stands. */
    public function enable(HtaccessLockActor $actor): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_ENABLE, static fn (array $raw): array => [
            'raw' => ['enabled' => true] + $raw,
            'detail' => null,
        ]);
    }

    /**
     * Lift the lock: removes the managed block from .htaccess but keeps the saved whitelist for next time.
     * Nothing is validated — there is no input, and this is the lockout-recovery path (console), which must
     * work even when the stored policy would no longer pass today's validation.
     */
    public function disable(HtaccessLockActor $actor): HtaccessLockResult
    {
        $this->authorize($actor);

        return $this->mutex->synchronized(function () use ($actor): HtaccessLockResult {
            $before = $this->manager->settings();
            $after = new HtaccessLockSettings(false, $before->ips, $before->exemptPaths, $before->statusCode, $before->errorFile);

            return $this->commit($actor, self::ACTION_DISABLE, $before, $after, null);
        });
    }

    public function addIp(HtaccessLockActor $actor, string $ip): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_IP_ADD, function (array $raw, HtaccessLockSettings $current) use ($ip): array|HtaccessLockResult {
            $canonical = $this->validator->normalizeIp($ip);
            if ($canonical === null) {
                return HtaccessLockResult::rejected([sprintf('"%s" is not a valid IPv4/IPv6 address or CIDR (a /0 prefix is not allowed).', $this->printable($ip))], $current);
            }
            if (\in_array($canonical, $current->ips, true)) {
                return HtaccessLockResult::conflict(sprintf('%s is already whitelisted.', $canonical), $current);
            }

            return ['raw' => ['ips' => implode("\n", [...$current->ips, $canonical])] + $raw, 'detail' => 'ip=' . $canonical];
        });
    }

    public function removeIp(HtaccessLockActor $actor, string $ip): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_IP_REMOVE, function (array $raw, HtaccessLockSettings $current) use ($ip): array|HtaccessLockResult {
            $canonical = $this->validator->normalizeIp($ip);
            if ($canonical === null) {
                return HtaccessLockResult::rejected([sprintf('"%s" is not a valid IPv4/IPv6 address or CIDR.', $this->printable($ip))], $current);
            }
            if (!\in_array($canonical, $current->ips, true)) {
                return HtaccessLockResult::notFound(sprintf('%s is not whitelisted.', $canonical), $current);
            }

            $remaining = array_values(array_filter($current->ips, static fn (string $entry): bool => $entry !== $canonical));

            return ['raw' => ['ips' => implode("\n", $remaining)] + $raw, 'detail' => 'ip=' . $canonical];
        });
    }

    public function addExemptPath(HtaccessLockActor $actor, string $path): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_EXEMPT_ADD, function (array $raw, HtaccessLockSettings $current) use ($path): array|HtaccessLockResult {
            $normalized = $this->validator->normalizeExemptPath($path);
            if ($normalized === null) {
                return HtaccessLockResult::rejected([sprintf('"%s" is not an acceptable exempt path: it must start with "/" (letters, digits and / . _ ~ %% - only, optional trailing *) and may not be "/*".', $this->printable($path))], $current);
            }
            if (\in_array($normalized, $current->exemptPaths, true)) {
                return HtaccessLockResult::conflict(sprintf('%s is already exempt.', $normalized), $current);
            }

            return ['raw' => ['exempt_paths' => implode("\n", [...$current->exemptPaths, $normalized])] + $raw, 'detail' => 'path=' . $normalized];
        });
    }

    public function removeExemptPath(HtaccessLockActor $actor, string $path): HtaccessLockResult
    {
        return $this->mutate($actor, self::ACTION_EXEMPT_REMOVE, function (array $raw, HtaccessLockSettings $current) use ($path): array|HtaccessLockResult {
            $normalized = $this->validator->normalizeExemptPath($path);
            if ($normalized === null) {
                return HtaccessLockResult::rejected([sprintf('"%s" is not an acceptable exempt path.', $this->printable($path))], $current);
            }
            if (!\in_array($normalized, $current->exemptPaths, true)) {
                return HtaccessLockResult::notFound(sprintf('%s is not exempt.', $normalized), $current);
            }

            $remaining = array_values(array_filter($current->exemptPaths, static fn (string $entry): bool => $entry !== $normalized));

            return ['raw' => ['exempt_paths' => implode("\n", $remaining)] + $raw, 'detail' => 'path=' . $normalized];
        });
    }

    /**
     * Prove against the real web server that the lock is enforced (ADR-059). Briefly swaps temporary blocks
     * into .htaccess, so real visitors are blocked for a few seconds while it runs.
     */
    public function runSelfTest(HtaccessLockActor $actor, string $scheme, string $host, int $port): HtaccessLockSelfTestReport
    {
        $this->authorize($actor);

        // Serialised with changes: the test swaps temporary blocks into .htaccess and then restores its snapshot,
        // which would silently undo a change committed in between.
        $report = $this->mutex->synchronized(fn (): HtaccessLockSelfTestReport => $this->selfTest->run($scheme, $host, $port, $this->manager->settings()));
        $this->manager->storeLastTest($report);
        $this->audit($actor, self::ACTION_TEST, $report->passed() ? 'success' : 'failure', null);

        return $report;
    }

    // ---- the funnel ----------------------------------------------------------------------------------------

    /**
     * @param \Closure(array{enabled:bool,ips:string,exempt_paths:string,status_code:string,error_file:string}, HtaccessLockSettings): (array{raw:array<string,mixed>,detail:?string}|HtaccessLockResult) $derive
     */
    private function mutate(HtaccessLockActor $actor, string $action, \Closure $derive): HtaccessLockResult
    {
        $this->authorize($actor);

        // Read, derive, validate and write as ONE serialised step (issue #44): a change is a read-modify-write of
        // the whole policy, so two unserialised ones each started from the same snapshot and the last writer won.
        return $this->mutex->synchronized(fn (): HtaccessLockResult => $this->mutateLocked($actor, $action, $derive));
    }

    /**
     * @param \Closure(array{enabled:bool,ips:string,exempt_paths:string,status_code:string,error_file:string}, HtaccessLockSettings): (array{raw:array<string,mixed>,detail:?string}|HtaccessLockResult) $derive
     */
    private function mutateLocked(HtaccessLockActor $actor, string $action, \Closure $derive): HtaccessLockResult
    {
        $current = $this->manager->settings();
        $derived = $derive($this->rawOf($current), $current);

        if ($derived instanceof HtaccessLockResult) {
            $this->audit($actor, $action, 'failure', $derived->outcome->value . ': ' . $derived->errors[0]);

            return $derived;
        }

        $raw = $derived['raw'];
        $validation = $this->validator->validate(
            (bool) $raw['enabled'],
            (string) $raw['ips'],
            (string) $raw['exempt_paths'],
            (string) $raw['status_code'],
            (string) $raw['error_file'],
            $actor->console || $actor->ip === '' ? null : $actor->ip,
            $this->manager->file()->docroot(),
        );

        if (!$validation->isValid()) {
            $this->audit($actor, $action, 'failure', 'rejected: ' . \count($validation->errors) . ' validation error(s)');

            return HtaccessLockResult::rejected($validation->errors, $current);
        }

        return $this->commit($actor, $action, $current, $validation->settings, $derived['detail']);
    }

    /** Validated settings in, .htaccess + config written, one audit row, one event. The only place a change is applied. */
    private function commit(HtaccessLockActor $actor, string $action, HtaccessLockSettings $before, HtaccessLockSettings $after, ?string $detail): HtaccessLockResult
    {
        try {
            $this->manager->save($after);
        } catch (\RuntimeException $e) {
            $this->audit($actor, $action, 'failure', 'write failed');

            return HtaccessLockResult::writeFailed('Could not update .htaccess: ' . $e->getMessage(), $before);
        }

        $this->audit($actor, $action, 'success', trim(($detail !== null ? $detail . ' ' : '') . sprintf(
            'enabled=%s ips=%d exempt_paths=%d status=%d error_file=%s',
            $after->enabled ? 'yes' : 'no',
            \count($after->ips),
            \count($after->exemptPaths),
            $after->statusCode,
            $after->errorFile === '' ? '(none)' : $after->errorFile,
        )));
        $this->dispatcher->dispatch(new HtaccessLockChangedEvent($action, $before, $after, $actor));

        return HtaccessLockResult::applied($after);
    }

    private function authorize(HtaccessLockActor $actor): void
    {
        if (!$actor->mayManage()) {
            throw new HtaccessLockForbiddenException('The Htaccess Lock is for tech support only.');
        }
    }

    private function audit(HtaccessLockActor $actor, string $action, string $outcome, ?string $context): void
    {
        $this->auditLogger->log($actor->email, 'admin', $actor->console ? 'cli' : ($actor->ip !== '' ? $actor->ip : '0.0.0.0'), $action, $outcome, $context);
    }

    /** @return array{enabled:bool,ips:string,exempt_paths:string,status_code:string,error_file:string} */
    private function rawOf(HtaccessLockSettings $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'ips' => implode("\n", $settings->ips),
            'exempt_paths' => implode("\n", $settings->exemptPaths),
            'status_code' => (string) $settings->statusCode,
            'error_file' => $settings->errorFile,
        ];
    }

    private function printable(string $value): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '', 0, 60);
    }
}
