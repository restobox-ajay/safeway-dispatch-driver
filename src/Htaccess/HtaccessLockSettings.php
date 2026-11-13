<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * The validated, normalised Htaccess Lock policy (ADR-059). Only ever built by
 * {@see HtaccessLockValidator} (or read back from config), so every value here is already safe to
 * render into an .htaccess file: IPs/CIDRs are canonical, paths match a strict character class.
 */
final readonly class HtaccessLockSettings
{
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;

    /**
     * @param list<string> $ips         IPv4/IPv6 addresses or CIDRs allowed through
     * @param list<string> $exemptPaths URL paths (no domain) reachable from ANY ip; trailing `*` = prefix
     * @param string       $errorFile   URL path of a file under the docroot to show when blocked; '' = empty body
     */
    public function __construct(
        public bool $enabled,
        public array $ips,
        public array $exemptPaths,
        public int $statusCode,
        public string $errorFile,
    ) {
    }
}
