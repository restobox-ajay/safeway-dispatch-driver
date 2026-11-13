<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Outcome of a {@see UserAccountAdminService} create/update call: either the written user or a
 * map of field-keyed validation errors. Both admin surfaces (web + API) consume the SAME result
 * and map it to their own response shape (flash+redirect / form re-render for the web,
 * 201/422 JSON for the API), so the one validation path can never drift between them
 * (FEATURE-103 AC1).
 *
 * @phpstan-type FieldErrors array<string, string>
 */
final class UserWriteResult
{
    /**
     * @param FieldErrors $errors
     */
    private function __construct(
        public readonly ?User $user,
        public readonly array $errors,
    ) {}

    public static function success(User $user): self
    {
        return new self($user, []);
    }

    /**
     * @param FieldErrors $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self(null, $errors);
    }

    public function isSuccess(): bool
    {
        return $this->errors === [];
    }
}
