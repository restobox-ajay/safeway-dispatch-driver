<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Bundle\AuthPasswordPolicy\Service\PasswordPolicyService;
use App\Service\ConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyServiceTest extends TestCase
{
    private ConfigService&MockObject $config;
    private PasswordPolicyService $service;

    protected function setUp(): void
    {
        $this->config  = $this->createMock(ConfigService::class);
        $this->service = new PasswordPolicyService($this->config);
    }

    /**
     * Stub ConfigService so that getInt() returns $minLength for the
     * min_length key (its default otherwise) and getBool() returns false for
     * every character-class rule (their defaults).
     */
    private function withMinLength(int $minLength): void
    {
        $this->config->method('getInt')->willReturnCallback(
            static fn (string $key, int $default = 0): int =>
                $key === 'password_policy.min_length' ? $minLength : $default
        );
        $this->config->method('getBool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $default
        );
    }

    /** AC1/AC3: floor is enforced when min_length is unset (config returns 0). */
    public function testHardFloorEnforcedWhenMinLengthUnset(): void
    {
        $this->withMinLength(0);

        $errors = $this->service->validate(str_repeat('a', PasswordPolicyService::HARD_MIN_LENGTH - 1));
        $this->assertNotEmpty($errors, 'A password below the hard floor must be rejected even with no configured minimum.');
    }

    /** AC1: a password exactly at the floor passes when no other rule is set. */
    public function testPasswordAtHardFloorPassesWithNoOtherRules(): void
    {
        $this->withMinLength(0);

        $errors = $this->service->validate(str_repeat('a', PasswordPolicyService::HARD_MIN_LENGTH));
        $this->assertSame([], $errors);
    }

    /** AC1: a configured minimum ABOVE the floor takes precedence. */
    public function testConfiguredMinimumAboveFloorIsEnforced(): void
    {
        $this->withMinLength(12);

        $errors = $this->service->validate(str_repeat('a', 8));
        $this->assertNotEmpty($errors, 'Eight characters must be rejected when the configured minimum is 12.');
    }

    /** AC1: a configured minimum BELOW the floor is clamped up to the floor. */
    public function testConfiguredMinimumBelowFloorIsClampedToFloor(): void
    {
        $this->withMinLength(3);

        // 7 chars satisfies the configured 3 but not the hard floor of 8.
        $errors = $this->service->validate(str_repeat('a', PasswordPolicyService::HARD_MIN_LENGTH - 1));
        $this->assertNotEmpty($errors, 'The hard floor must override a configured minimum that is lower than it.');
    }

    /** AC2: a password longer than the maximum is rejected. */
    public function testPasswordOverMaximumLengthIsRejected(): void
    {
        $this->withMinLength(0);

        $errors = $this->service->validate(str_repeat('a', PasswordPolicyService::MAX_LENGTH + 1));
        $this->assertNotEmpty($errors, 'A password longer than the maximum must be rejected.');
    }

    /** AC2: a password exactly at the maximum is accepted. */
    public function testPasswordAtMaximumLengthIsAccepted(): void
    {
        $this->withMinLength(0);

        $errors = $this->service->validate(str_repeat('a', PasswordPolicyService::MAX_LENGTH));
        $this->assertSame([], $errors);
    }

    /** Regression: the character-class rules still flag and pass correctly. */
    public function testRequireUppercaseRuleStillApplies(): void
    {
        $this->config->method('getInt')->willReturnCallback(
            static fn (string $key, int $default = 0): int => $default
        );
        $this->config->method('getBool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool =>
                $key === 'password_policy.require_uppercase' ? true : $default
        );

        $this->assertNotEmpty(
            $this->service->validate('alllowercase'),
            'A password with no uppercase letter must be rejected when require_uppercase is on.'
        );
        $this->assertSame(
            [],
            $this->service->validate('HasUppercase'),
            'A compliant password must pass when only require_uppercase is on.'
        );
    }
}
