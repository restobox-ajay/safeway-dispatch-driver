<?php

declare(strict_types=1);

namespace App\Prune;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Prunes expired rows of the PdoSessionHandler `sessions` table itself (issue #30). Nothing else guarantees they
 * are ever deleted: the handler only deletes inside PHP's probabilistic session GC, whose probability is left to
 * php.ini — and Debian/Ubuntu ship session.gc_probability = 0 — so on such a host every visitor that ever started
 * a session (e.g. GET /login storing a CSRF token) left a row in the SQLite file forever (ADR-020: ephemeral rows
 * are flushed on a schedule).
 *
 * sess_lifetime is the ABSOLUTE expiry timestamp the handler writes (time() + ttl — issue #36), and the handler's
 * own GC uses the same predicate (`sess_lifetime < :time`). `sessions` is a DBAL-only table (not an entity), so
 * this is raw SQL.
 */
final readonly class SessionPruner implements PrunerInterface
{
    public function __construct(private Connection $connection) {}

    public function name(): string
    {
        return 'sessions';
    }

    public function count(\DateTimeImmutable $now): int
    {
        // $now is bound as an INTEGER so the comparison is numeric by construction, whatever the column's declared
        // affinity (it is INTEGER today, which would also coerce a text-bound value; this does not rely on that).
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM sessions WHERE sess_lifetime < :now',
            ['now' => $now->getTimestamp()],
            ['now' => ParameterType::INTEGER],
        );
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM sessions WHERE sess_lifetime < :now',
            ['now' => $now->getTimestamp()],
            ['now' => ParameterType::INTEGER],
        );
    }
}
