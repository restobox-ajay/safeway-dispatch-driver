<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the two audit write modes (FEATURE-106 / review C10). log()'s independent
 * durable DBAL INSERT is verified end-to-end in the functional AuditFlushFootgunTest (it writes a
 * row AND does not flush the unit of work); here we cover the parts that need no real connection:
 * logDeferred()'s persist-without-flush contract and the enum guard on log().
 */
final class AuditLoggerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = new AuditLogger($this->em);
    }

    // C10/FEATURE-106: logDeferred() persists into the current unit of work but does NOT flush —
    // the caller commits it atomically with the business change inside a transaction.
    public function testLogDeferredPersistsButDoesNotFlush(): void
    {
        $persisted = null;

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $entry) use (&$persisted) {
                $persisted = $entry;
                return true;
            }));

        $this->em->expects($this->never())->method('flush');

        $this->auditLogger->logDeferred('admin@example.com', 'admin', '10.0.0.1', 'admin.user_create', 'success', 'target@example.com');

        $this->assertNotNull($persisted);
        $this->assertSame('admin@example.com', $persisted->getActor());
        $this->assertSame('admin', $persisted->getActorType());
        $this->assertSame('10.0.0.1', $persisted->getIp());
        $this->assertSame('admin.user_create', $persisted->getAction());
        $this->assertSame('success', $persisted->getOutcome());
        $this->assertSame('target@example.com', $persisted->getContext());
        $this->assertInstanceOf(\DateTimeImmutable::class, $persisted->getCreatedAt());
    }

    // The enum guard rejects invalid input BEFORE touching the database (never persists / connects).
    public function testLogRejectsInvalidActorType(): void
    {
        $this->em->expects($this->never())->method('getConnection');
        $this->expectException(\InvalidArgumentException::class);

        $this->auditLogger->log('x', 'not-a-principal', '127.0.0.1', 'login', 'success');
    }

    public function testLogRejectsInvalidOutcome(): void
    {
        $this->em->expects($this->never())->method('getConnection');
        $this->expectException(\InvalidArgumentException::class);

        $this->auditLogger->log('x', 'user', '127.0.0.1', 'login', 'maybe');
    }

    public function testLogDeferredRejectsInvalidActorType(): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        $this->auditLogger->logDeferred('x', 'not-a-principal', '127.0.0.1', 'admin.user_create', 'success');
    }
}
