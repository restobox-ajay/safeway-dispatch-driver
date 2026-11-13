<?php

declare(strict_types=1);

namespace App\Service;

interface WebhookDispatcherInterface
{
    public function dispatch(string $url, array $payload): void;
}
