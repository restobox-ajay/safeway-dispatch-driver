<?php

declare(strict_types=1);

namespace App\Htaccess;

final readonly class HtaccessLockResult
{
    /** @param list<string> $errors */
    private function __construct(
        public HtaccessLockOutcome $outcome,
        public array $errors,
        public HtaccessLockSettings $settings,
    ) {
    }

    public static function applied(HtaccessLockSettings $settings): self
    {
        return new self(HtaccessLockOutcome::Applied, [], $settings);
    }

    /** @param list<string> $errors */
    public static function rejected(array $errors, HtaccessLockSettings $current): self
    {
        return new self(HtaccessLockOutcome::Rejected, $errors, $current);
    }

    public static function conflict(string $message, HtaccessLockSettings $current): self
    {
        return new self(HtaccessLockOutcome::Conflict, [$message], $current);
    }

    public static function notFound(string $message, HtaccessLockSettings $current): self
    {
        return new self(HtaccessLockOutcome::NotFound, [$message], $current);
    }

    public static function writeFailed(string $message, HtaccessLockSettings $current): self
    {
        return new self(HtaccessLockOutcome::WriteFailed, [$message], $current);
    }

    public function isApplied(): bool
    {
        return $this->outcome === HtaccessLockOutcome::Applied;
    }
}
