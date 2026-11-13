<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\DriverApiSessionRepository;

/**
 * Prunes driver_api_session rows unused for longer than the admin API's refresh token lives (30 days):
 * a driver who closed the browser without logging out leaves a row that can never call the API again.
 */
final readonly class DriverApiSessionPruner implements PrunerInterface
{
    public function __construct(private DriverApiSessionRepository $sessions) {}

    public function name(): string
    {
        return 'driver_api_session';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->sessions->countIdle($now);
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->sessions->deleteIdle($now);
    }
}
