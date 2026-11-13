<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\AuditLogRepository;
use App\Service\ConfigService;

/**
 * Prunes audit_log rows older than `audit_log.retention_days` (default 90). Supersedes the standalone
 * app:audit-log:prune command (ADR-008 → ADR-048).
 */
final readonly class AuditLogPruner implements PrunerInterface
{
    public function __construct(
        private AuditLogRepository $auditLog,
        private ConfigService $config,
    ) {}

    public function name(): string
    {
        return 'audit_log';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->auditLog->countOlderThan($this->cutoff($now));
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->auditLog->deleteOlderThan($this->cutoff($now));
    }

    private function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $days = $this->config->getInt('audit_log.retention_days', 90);

        return $now->modify("-{$days} days");
    }
}
