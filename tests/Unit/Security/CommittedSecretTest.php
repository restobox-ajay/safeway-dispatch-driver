<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for security review C1: a real APP_SECRET must never live in the
 * tracked .env.dev (it keys the remember-me HMAC, CSRF tokens, and signed verify-email
 * URLs — anyone with repo access could forge them). The real value belongs only in the
 * gitignored .env.dev.local / the environment.
 */
final class CommittedSecretTest extends TestCase
{
    public function testTrackedDevEnvDoesNotCommitAnAppSecret(): void
    {
        $envDev = file_get_contents(dirname(__DIR__, 3) . '/.env.dev');
        self::assertIsString($envDev);

        // APP_SECRET in the tracked .env.dev must be empty (value supplied via .env.dev.local).
        self::assertMatchesRegularExpression(
            '/^APP_SECRET=\s*$/m',
            $envDev,
            '.env.dev must not commit a real APP_SECRET; set it in the gitignored .env.dev.local instead.'
        );
    }
}
