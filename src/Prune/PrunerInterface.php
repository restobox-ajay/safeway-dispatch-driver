<?php

declare(strict_types=1);

namespace App\Prune;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A single prunable domain (one table). Each pruner owns its predicate ("what is prunable") and reads
 * its OWN retention from ConfigService internally; the shared app:prune harness (App\Command\PruneCommand)
 * owns the boring infrastructure (sorting, dry-run, per-pruner error isolation, one audit row).
 *
 * The `auth.pruner` autoconfigure tag is what lets PruneCommand collect every contributor via
 * #[AutowireIterator] — the same extension pattern as ConfigPageProviderInterface / ConfigPageRegistry.
 * A bundle pruner is autoconfigured (hence tagged) ONLY when its bundle is registered, so modularity
 * falls out for free: no services.php or compiler-pass edits, and the pruner simply disappears from
 * app:prune when the bundle is uninstalled (FEATURE-147 / ADR-048).
 */
#[AutoconfigureTag('auth.pruner')]
interface PrunerInterface
{
    /** Stable slug used by --only and in output; matches the table name (e.g. 'audit_log'). */
    public function name(): string;

    /** Rows that WOULD be deleted right now (dry-run). MUST NOT modify anything. */
    public function count(\DateTimeImmutable $now): int;

    /** Delete prunable rows; returns rows deleted. Idempotent. */
    public function prune(\DateTimeImmutable $now): int;
}
