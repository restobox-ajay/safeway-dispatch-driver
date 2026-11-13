<?php

declare(strict_types=1);

namespace App\AdminPassword;

use App\Entity\Admin;

final class AdminPasswordChangeResult
{
    private function __construct(
        public readonly PasswordChangeOutcome $outcome,
        public readonly ?string $message = null,
        public readonly ?Admin $admin = null,
    ) {
    }

    public static function changed(Admin $admin): self
    {
        return new self(PasswordChangeOutcome::Changed, null, $admin);
    }

    public static function rejected(string $message): self
    {
        return new self(PasswordChangeOutcome::Rejected, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(PasswordChangeOutcome::Invalid, $message);
    }

    public static function forbidden(): self
    {
        return new self(PasswordChangeOutcome::Forbidden);
    }

    public function isChanged(): bool
    {
        return $this->outcome === PasswordChangeOutcome::Changed;
    }
}
