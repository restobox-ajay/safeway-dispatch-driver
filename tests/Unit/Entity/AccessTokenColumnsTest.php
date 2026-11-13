<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AdminAccessToken;
use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-103 (AXIS-2 / review C8): PersonalAccessToken and AdminAccessToken share the dumb-data
 * trait AccessTokenColumns for their identical fields/lifecycle, while the owner-id column
 * (user_id vs admin_id) stays realm-specific. This proves the two distinct classes behave
 * identically through the shared trait and that each keeps its own owner-id accessor.
 */
final class AccessTokenColumnsTest extends TestCase
{
    public function testSharedFieldsBehaveIdenticallyAcrossRealms(): void
    {
        $expires = new \DateTimeImmutable('+30 days');

        $userToken = new PersonalAccessToken(7, 'ci', 'hash-u', $expires);
        $adminToken = new AdminAccessToken(9, 'ci', 'hash-a', $expires);

        foreach ([$userToken, $adminToken] as $token) {
            $this->assertNull($token->getId());
            $this->assertSame('ci', $token->getName());
            $this->assertNotSame('', $token->getTokenHash());
            $this->assertSame($expires, $token->getExpiresAt());
            $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
            $this->assertNull($token->getLastUsedAt());
            $this->assertNull($token->getRevokedAt());
            $this->assertFalse($token->isRevoked());
            $this->assertFalse($token->isExpired());
            $this->assertTrue($token->isActive());
        }
    }

    public function testOwnerIdColumnStaysRealmSpecific(): void
    {
        $userToken = new PersonalAccessToken(7, 'ci', 'hash-u');
        $adminToken = new AdminAccessToken(9, 'ci', 'hash-a');

        $this->assertSame(7, $userToken->getUserId());
        $this->assertSame(9, $adminToken->getAdminId());
    }

    public function testSetLastUsedAtAcrossRealms(): void
    {
        $stamp = new \DateTimeImmutable('-5 minutes');

        $userToken = new PersonalAccessToken(1, 'ci', 'h');
        $adminToken = new AdminAccessToken(1, 'ci', 'h');

        $userToken->setLastUsedAt($stamp);
        $adminToken->setLastUsedAt($stamp);

        $this->assertSame($stamp, $userToken->getLastUsedAt());
        $this->assertSame($stamp, $adminToken->getLastUsedAt());
    }

    public function testRevokeAcrossRealms(): void
    {
        $userToken = new PersonalAccessToken(1, 'ci', 'h');
        $adminToken = new AdminAccessToken(1, 'ci', 'h');

        foreach ([$userToken, $adminToken] as $token) {
            $this->assertTrue($token->isActive());
            $token->revoke();
            $this->assertTrue($token->isRevoked());
            $this->assertNotNull($token->getRevokedAt());
            $this->assertFalse($token->isActive());
        }
    }

    public function testExpiredTokenIsNotActive(): void
    {
        $userToken = new PersonalAccessToken(1, 'ci', 'h', new \DateTimeImmutable('-1 second'));
        $adminToken = new AdminAccessToken(1, 'ci', 'h', new \DateTimeImmutable('-1 second'));

        foreach ([$userToken, $adminToken] as $token) {
            $this->assertTrue($token->isExpired());
            $this->assertFalse($token->isActive());
        }
    }

    public function testNullExpiryNeverExpires(): void
    {
        $userToken = new PersonalAccessToken(1, 'ci', 'h');
        $adminToken = new AdminAccessToken(1, 'ci', 'h');

        foreach ([$userToken, $adminToken] as $token) {
            $this->assertNull($token->getExpiresAt());
            $this->assertFalse($token->isExpired());
        }
    }
}
