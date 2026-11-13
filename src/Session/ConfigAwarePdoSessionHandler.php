<?php

declare(strict_types=1);

namespace App\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * PdoSessionHandler wired to a DB-backed, per-request TTL (ADR-051).
 *
 * Symfony's session lifetime is global framework config, but PdoSessionHandler accepts a `ttl`
 * CLOSURE that it evaluates on every write and every timestamp refresh. That is the hook this class
 * uses to vary the lifetime per session — a baseline idle window for everyone, a much longer one for
 * an admin who ticked "Remember me" — without a per-firewall session config (which Symfony has no
 * concept of) and without a remember-me bearer cookie.
 *
 * Subclassing (rather than configuring `ttl` in services.yaml) is required because a closure cannot
 * be expressed in YAML service arguments.
 */
final class ConfigAwarePdoSessionHandler extends PdoSessionHandler
{
    /**
     * @param array<string,mixed> $options Passed through to PdoSessionHandler; `ttl` is overwritten.
     */
    public function __construct(
        \PDO $pdo,
        SessionTtlResolver $ttlResolver,
        array $options = [],
    ) {
        $options['ttl'] = static fn (): int => $ttlResolver->resolve();

        parent::__construct($pdo, $options);
    }
}
