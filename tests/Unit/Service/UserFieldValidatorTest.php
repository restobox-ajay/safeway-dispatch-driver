<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UserFieldValidator;
use PHPUnit\Framework\TestCase;

final class UserFieldValidatorTest extends TestCase
{
    private UserFieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UserFieldValidator();
    }

    public function testValidateRoleAcceptsAllowedRole(): void
    {
        self::assertNull($this->validator->validateRole('ROLE_USER'));
    }

    public function testValidateRoleRejectsAdminRole(): void
    {
        // A User carrying an admin role would collapse the separate-firewall boundary.
        self::assertNotNull($this->validator->validateRole('ROLE_ADMIN'));
    }

    public function testValidateRoleRejectsGarbage(): void
    {
        self::assertNotNull($this->validator->validateRole('ROLE_WIZARD'));
        self::assertNotNull($this->validator->validateRole(''));
    }

    public function testValidateStatusAcceptsActiveAndInactive(): void
    {
        self::assertNull($this->validator->validateStatus('active'));
        self::assertNull($this->validator->validateStatus('inactive'));
    }

    public function testValidateStatusRejectsUnknownStatus(): void
    {
        self::assertNotNull($this->validator->validateStatus('banned'));
        self::assertNotNull($this->validator->validateStatus(''));
    }
}
