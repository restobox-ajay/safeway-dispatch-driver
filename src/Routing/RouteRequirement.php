<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Shared route requirements (issue #53).
 */
final class RouteRequirement
{
    /**
     * A numeric `{id}` that always fits a PHP int: at most 18 digits (PHP_INT_MAX has 19). Anything else — 'abc',
     * '7abc', a 20-digit overflow — matches no route and is a plain 404, instead of reaching an `int $id` controller
     * argument as a raw string and failing with a TypeError (500).
     */
    public const ID = '\d{1,18}';

    private function __construct()
    {
    }
}
