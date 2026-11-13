<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Service;

use App\Service\ConfigService;

/**
 * Password strength validation. Moved into auth-password-policy-bundle (FEATURE-145 / ADR-045); the logic
 * is unchanged. Core reaches it only through the {@see \App\Security\PasswordPolicyManagerInterface} port.
 */
class PasswordPolicyService
{
    /**
     * Absolute minimum length enforced regardless of admin config. Even when the
     * admin leaves min_length unset (or sets it below this value), passwords
     * shorter than this are rejected — the policy fails closed rather than open.
     */
    public const HARD_MIN_LENGTH = 8;

    /**
     * Maximum length. bcrypt silently truncates input to 72 bytes, so characters
     * beyond that boundary contribute nothing to the stored hash and can mask a
     * weaker effective password. Reject anything longer outright.
     */
    public const MAX_LENGTH = 72;

    public function __construct(private readonly ConfigService $configService) {}

    /** @return list<string> List of violation messages; empty = password is valid */
    public function validate(string $password): array
    {
        $errors = [];

        // strlen() measures bytes, which is exactly the unit bcrypt truncates on.
        $length = strlen($password);

        // Effective minimum: the admin may raise the bar above the hard floor but
        // can never lower it below it. An unset min_length (getInt → 0) clamps up
        // to the floor.
        $minLength = max($this->configService->getInt('password_policy.min_length', 0), self::HARD_MIN_LENGTH);
        if ($length < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters.";
        }

        if ($length > self::MAX_LENGTH) {
            $max = self::MAX_LENGTH;
            $errors[] = "Password must be at most {$max} characters.";
        }

        if ($this->configService->getBool('password_policy.require_uppercase', false)) {
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter.';
            }
        }

        if ($this->configService->getBool('password_policy.require_number', false)) {
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number.';
            }
        }

        if ($this->configService->getBool('password_policy.require_symbol', false)) {
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'Password must contain at least one symbol.';
            }
        }

        return $errors;
    }
}
