<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    public function testSetActorTypeAcceptsAllowedValues(): void
    {
        $log = new AuditLog();
        $log->setActorType('user');
        $this->assertSame('user', $log->getActorType());

        $log->setActorType('admin');
        $this->assertSame('admin', $log->getActorType());
    }

    public function testSetActorTypeRejectsUnknownValue(): void
    {
        $log = new AuditLog();
        $this->expectException(\InvalidArgumentException::class);
        $log->setActorType('robot');
    }

    public function testSetOutcomeAcceptsAllowedValues(): void
    {
        $log = new AuditLog();
        $log->setOutcome('success');
        $this->assertSame('success', $log->getOutcome());

        $log->setOutcome('failure');
        $this->assertSame('failure', $log->getOutcome());
    }

    public function testSetOutcomeRejectsUnknownValue(): void
    {
        $log = new AuditLog();
        $this->expectException(\InvalidArgumentException::class);
        $log->setOutcome('maybe');
    }
}
