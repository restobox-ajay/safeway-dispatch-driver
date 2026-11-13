<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Bundle\AuthWebhook\Service\HttpWebhookDispatcher;

/**
 * Test double for HttpWebhookDispatcher (the single-attempt sender).
 * Overrides sendHttp() to return queued response codes instead of making a real HTTP call.
 */
class ControlledHttpWebhookDispatcher extends HttpWebhookDispatcher
{
    private static array $responseQueue = [];

    public static function queueResponses(int ...$codes): void
    {
        self::$responseQueue = array_values($codes);
    }

    public static function reset(): void
    {
        self::$responseQueue = [];
    }

    protected function sendHttp(string $url, string $json): int
    {
        if (!empty(self::$responseQueue)) {
            return array_shift(self::$responseQueue);
        }

        return 200;
    }
}
