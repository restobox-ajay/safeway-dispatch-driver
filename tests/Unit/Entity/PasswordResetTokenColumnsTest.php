<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AdminPasswordResetToken;
use App\Entity\PasswordResetToken;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-103 (AXIS-2 / review C8, C32): PasswordResetToken and AdminPasswordResetToken share the
 * dumb-data trait PasswordResetTokenColumns. This proves the two distinct classes behave identically
 * through the shared trait — and, critically, that getCreatedAt() is now present on BOTH realms (the
 * C32 drift: the admin token previously lacked it while carrying declare(strict_types=1)).
 */
final class PasswordResetTokenColumnsTest extends TestCase
{
    public function testConstructorAndGettersAreIdenticalAcrossRealms(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');

        $user = new PasswordResetToken('user@example.com', 'hash-u', $expires);
        $admin = new AdminPasswordResetToken('admin@example.com', 'hash-a', $expires);

        foreach ([$user, $admin] as $token) {
            $this->assertNull($token->getId());
            $this->assertNotSame('', $token->getEmail());
            $this->assertNotSame('', $token->getTokenHash());
            $this->assertSame($expires, $token->getExpiresAt());
            // C32 regression guard: getCreatedAt() must exist and be populated on BOTH realms.
            $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
            $this->assertNull($token->getUsedAt());
        }

        $this->assertSame('user@example.com', $user->getEmail());
        $this->assertSame('admin@example.com', $admin->getEmail());
    }

    public function testMarkUsedFlipsUsedStateAcrossRealms(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');

        $user = new PasswordResetToken('u@example.com', 'h', $expires);
        $admin = new AdminPasswordResetToken('a@example.com', 'h', $expires);

        foreach ([$user, $admin] as $token) {
            $this->assertFalse($token->isUsed());
            $this->assertNull($token->getUsedAt());

            $token->markUsed();

            $this->assertTrue($token->isUsed());
            $this->assertInstanceOf(\DateTimeImmutable::class, $token->getUsedAt());
        }
    }

    public function testExpiryIsHonoured(): void
    {
        $future = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('+1 hour'));
        $past = new AdminPasswordResetToken('a@example.com', 'h', new \DateTimeImmutable('-1 hour'));

        $this->assertFalse($future->isExpired());
        $this->assertTrue($past->isExpired());
    }

    public function testIsValidRequiresUnusedAndUnexpiredAcrossRealms(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');

        $user = new PasswordResetToken('u@example.com', 'h', $expires);
        $admin = new AdminPasswordResetToken('a@example.com', 'h', $expires);

        foreach ([$user, $admin] as $token) {
            $this->assertTrue($token->isValid());
            $token->markUsed();
            $this->assertFalse($token->isValid());
        }
    }

    public function testExpiredTokenIsInvalidEvenWhenUnused(): void
    {
        $expired = new PasswordResetToken('u@example.com', 'h', new \DateTimeImmutable('-1 second'));

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isUsed());
        $this->assertFalse($expired->isValid());
    }
}
