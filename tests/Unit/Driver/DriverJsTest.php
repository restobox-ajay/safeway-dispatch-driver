<?php

declare(strict_types=1);

namespace App\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;

/**
 * The driver web app's JavaScript rules (tests/js/*.test.mjs, Node's built-in test runner — no npm packages):
 * run here so bin/verify-fast.sh covers them. Node is required, as it already is for this repo's release tooling.
 */
final class DriverJsTest extends TestCase
{
    public function testTheDriverAppJavaScriptRulesHold(): void
    {
        $root = \dirname(__DIR__, 3);
        $files = glob($root . '/tests/js/*.test.mjs') ?: [];
        $this->assertNotEmpty($files);

        $command = 'node --test ' . implode(' ', array_map('escapeshellarg', $files)) . ' 2>&1';
        exec($command, $output, $exit);
        $report = implode("\n", $output);

        $this->assertNotSame(127, $exit, "Node.js is needed to run the driver app's JavaScript tests:\n" . $report);
        $this->assertSame(0, $exit, $report);
        $this->assertMatchesRegularExpression('/^# fail 0$/m', $report);
        $this->assertDoesNotMatchRegularExpression('/^# pass 0$/m', $report, 'no JavaScript test ran');
    }
}
