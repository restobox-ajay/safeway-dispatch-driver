<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Makes a real HTTP request to this same site and reports the status code — the only way to learn
 * whether the web server actually enforces what we wrote into `.htaccess`.
 */
interface HtaccessProbeInterface
{
    /**
     * @return int|null the HTTP status code, or null when no HTTP response could be obtained
     */
    public function status(string $scheme, string $host, int $port, string $path): ?int;
}
