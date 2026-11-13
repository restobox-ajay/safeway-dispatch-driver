<?php

declare(strict_types=1);

namespace App\Driver;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the Safeway Dispatch system is: DISPATCH_URL in .env / .env.local (the Dispatch address, e.g. the dev
 * https://dispatch.safeway-dispatch.dev.rp021.webhelplogin.com). The app talks to {DISPATCH_URL}/driver-api/v1/.
 * The older DRIVER_API_BASE_URL (the full …/driver-api/v1/ address) is read only when DISPATCH_URL is empty.
 */
final class DispatchUrl
{
    public const API_PATH = '/driver-api/v1/';

    public function __construct(
        #[Autowire(env: 'default::DISPATCH_URL')] private readonly ?string $dispatchUrl = null,
        #[Autowire(env: 'default::DRIVER_API_BASE_URL')] private readonly ?string $legacyApiBase = null,
    ) {
    }

    /** The Driver API base the client calls ("…/driver-api/v1/"), or '' when nothing is set. */
    public function apiBase(): string
    {
        $url = trim((string) $this->dispatchUrl);
        if ($url !== '') {
            // A pasted API address works too: a trailing /driver-api/v1 is not doubled.
            return (string) preg_replace('#/driver-api(/v1)?$#', '', rtrim($url, '/')) . self::API_PATH;
        }
        $legacy = trim((string) $this->legacyApiBase);

        return $legacy === '' ? '' : rtrim($legacy, '/') . '/';
    }
}
