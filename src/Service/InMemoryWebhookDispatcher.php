<?php

declare(strict_types=1);

namespace App\Service;

class InMemoryWebhookDispatcher implements WebhookDispatcherInterface
{
    private static array $dispatched = [];

    public function dispatch(string $url, array $payload): void
    {
        self::$dispatched[] = ['url' => $url, 'payload' => $payload];
    }

    public static function getDispatched(): array
    {
        return self::$dispatched;
    }

    public static function reset(): void
    {
        self::$dispatched = [];
    }
}
