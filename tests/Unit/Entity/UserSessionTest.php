<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\UserSession;
use PHPUnit\Framework\TestCase;

final class UserSessionTest extends TestCase
{
    // FEATURE-123: the user_type discriminator was removed (admin sessions live in the dedicated
    // admin_sessions table), so UserSession is a plain user-scoped record. Verify the remaining
    // fields round-trip and the timestamps default on construction.
    public function testFieldsRoundTrip(): void
    {
        $session = new UserSession();
        $session->setSessionId('sess-abc-123');
        $session->setUserId(42);
        $session->setIp('203.0.113.5');
        $session->setUserAgent('TestAgent/1.0');

        $this->assertSame('sess-abc-123', $session->getSessionId());
        $this->assertSame(42, $session->getUserId());
        $this->assertSame('203.0.113.5', $session->getIp());
        $this->assertSame('TestAgent/1.0', $session->getUserAgent());
    }

    public function testTimestampsDefaultOnConstruction(): void
    {
        $session = new UserSession();

        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getLastActiveAt());
    }

    public function testLastActiveAtIsUpdatable(): void
    {
        $session = new UserSession();
        $newTime = new \DateTimeImmutable('2026-07-06 12:00:00');
        $session->setLastActiveAt($newTime);

        $this->assertSame($newTime, $session->getLastActiveAt());
    }
}
