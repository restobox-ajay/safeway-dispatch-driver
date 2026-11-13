<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AccountStatus;
use App\Enum\AuditOutcome;
use App\Enum\PrincipalType;
use PHPUnit\Framework\TestCase;

final class AccountStatusTest extends TestCase
{
    public function testAccountStatusValues(): void
    {
        $this->assertSame(['active', 'inactive'], AccountStatus::values());
    }

    public function testAccountStatusIsValid(): void
    {
        $this->assertTrue(AccountStatus::isValid('active'));
        $this->assertTrue(AccountStatus::isValid('inactive'));
        $this->assertFalse(AccountStatus::isValid('banned'));
        $this->assertFalse(AccountStatus::isValid('Active')); // case-sensitive
        $this->assertFalse(AccountStatus::isValid(''));
    }

    public function testPrincipalTypeValues(): void
    {
        $this->assertSame(['user', 'admin'], PrincipalType::values());
        $this->assertTrue(PrincipalType::isValid('user'));
        $this->assertTrue(PrincipalType::isValid('admin'));
        $this->assertFalse(PrincipalType::isValid('system'));
    }

    public function testAuditOutcomeValues(): void
    {
        $this->assertSame(['success', 'failure'], AuditOutcome::values());
        $this->assertTrue(AuditOutcome::isValid('success'));
        $this->assertTrue(AuditOutcome::isValid('failure'));
        $this->assertFalse(AuditOutcome::isValid('error'));
    }
}
