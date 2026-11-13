<?php

declare(strict_types=1);

namespace App\Htaccess;

/** Who is asking the gate to read or change the Htaccess Lock. Derived by the gate itself, never passed in by callers. */
final readonly class HtaccessLockActor
{
    private function __construct(
        public string $email,
        public string $ip,
        public bool $techSupport,
        public bool $console,
    ) {
    }

    public static function admin(string $email, string $ip, bool $techSupport): self
    {
        return new self($email, $ip, $techSupport, false);
    }

    /** The server shell (no HTTP request, no login): whoever has shell access may recover the lock. */
    public static function console(): self
    {
        return new self('console', '', true, true);
    }

    public function mayManage(): bool
    {
        return $this->console || $this->techSupport;
    }
}
