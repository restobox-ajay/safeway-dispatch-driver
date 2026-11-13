<?php

declare(strict_types=1);

namespace App\Htaccess;

final readonly class HtaccessLockValidation
{
    /** @param list<string> $errors */
    public function __construct(
        public ?HtaccessLockSettings $settings,
        public array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->settings !== null;
    }
}
