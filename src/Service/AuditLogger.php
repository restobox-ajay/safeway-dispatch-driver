<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Enum\AuditOutcome;
use App\Enum\PrincipalType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Two explicit audit write modes (review C10 / FEATURE-106):
 *
 * - {@see log()} is an INDEPENDENT DURABLE write (a dedicated DBAL INSERT). It never touches the
 *   Doctrine unit of work, so it can neither trigger a collateral commit of an unrelated dirty
 *   entity nor be undone by a later rollback of the request's business change. Use it for events
 *   that must be recorded regardless of a rolled-back business change: login success/failure,
 *   logout, security events, explicit 'failure' outcomes.
 *
 * - {@see logDeferred()} only persist()s the entry into the CURRENT unit of work and does NOT
 *   flush. The caller owns a single transaction boundary (prefer $em->wrapInTransaction) so the
 *   audit row and the business change it describes commit atomically — both or neither. Use it for
 *   the success-of-a-business-change case (admin create/update/delete).
 */
class AuditLogger
{
    /*
     * Column contract, enforced here in the one sink (issue #22): SQLite does not enforce declared VARCHAR
     * lengths, and the actor of a failed login is the attacker-supplied posted email. `context` (TEXT) is NOT
     * capped: only internal code writes it, and cutting it could hide part of an audited change (e.g. the end of
     * a long IP list in admin.config_update).
     */
    private const MAX_ACTOR_LENGTH = 255;   // audit_log.actor VARCHAR(255)
    private const MAX_IP_LENGTH = 45;       // audit_log.ip VARCHAR(45)
    private const MAX_ACTION_LENGTH = 100;  // audit_log.action VARCHAR(100)

    public function __construct(private EntityManagerInterface $em) {}

    public function log(string $actor, string $actorType, string $ip, string $action, string $outcome, ?string $context = null): void
    {
        $this->assertValid($actorType, $outcome);
        [$actor, $ip, $action, $context] = $this->clip($actor, $ip, $action, $context);

        // Dedicated DBAL INSERT, NOT $em->persist()+flush(): writing through the unit of work would
        // (a) flush every other pending change in the request (collateral commit — C10/AC4) and
        // (b) tie this row to the request transaction so a failed business change could roll it back.
        // A direct INSERT stands independent of the surrounding unit of work (AC3).
        $this->em->getConnection()->insert('audit_log', [
            'actor'      => $actor,
            'actor_type' => $actorType,
            'ip'         => $ip,
            'action'     => $action,
            'outcome'    => $outcome,
            'context'    => $context,
            'created_at' => new \DateTimeImmutable(),
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function logDeferred(string $actor, string $actorType, string $ip, string $action, string $outcome, ?string $context = null): void
    {
        [$actor, $ip, $action, $context] = $this->clip($actor, $ip, $action, $context);
        $entry = new AuditLog();
        $entry->setActor($actor);
        $entry->setActorType($actorType);
        $entry->setIp($ip);
        $entry->setAction($action);
        $entry->setOutcome($outcome);
        if ($context !== null) {
            $entry->setContext($context);
        }

        // Persist ONLY — no flush(). The caller commits this together with the business change
        // inside a single transaction (AC1/AC2).
        $this->em->persist($entry);
    }

    /** @return array{0:string,1:string,2:string,3:?string} */
    private function clip(string $actor, string $ip, string $action, ?string $context): array
    {
        return [
            mb_substr($actor, 0, self::MAX_ACTOR_LENGTH),
            mb_substr($ip, 0, self::MAX_IP_LENGTH),
            mb_substr($action, 0, self::MAX_ACTION_LENGTH),
            $context,
        ];
    }

    private function assertValid(string $actorType, string $outcome): void
    {
        if (!PrincipalType::isValid($actorType)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid actorType "%s"; allowed: %s',
                $actorType,
                implode(', ', PrincipalType::values()),
            ));
        }
        if (!AuditOutcome::isValid($outcome)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid outcome "%s"; allowed: %s',
                $outcome,
                implode(', ', AuditOutcome::values()),
            ));
        }
    }
}
