<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Maintenance\MaintenanceGate;
use PHPUnit\Framework\TestCase;

final class MaintenanceGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/maintenance_' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/var/*') ?: []);
        @rmdir($this->dir . '/var');
        @rmdir($this->dir);
    }

    public function testIsOffWithoutAFlagFile(): void
    {
        $this->assertNull((new MaintenanceGate($this->dir))->blockingState('203.0.113.9'));
    }

    public function testBlocksEveryoneExceptAllowedIpsWhileOn(): void
    {
        $gate = new MaintenanceGate($this->dir);
        $gate->enable('Updating', 30, ['198.51.100.7']);

        $this->assertSame('Updating', $gate->blockingState('203.0.113.9')['message']);
        $this->assertSame('Updating', $gate->blockingState(null)['message']);
        $this->assertNull($gate->blockingState('198.51.100.7'));
    }

    public function testExpiresOnItsOwnSoACrashedUpdateCannotLeaveTheSiteDown(): void
    {
        $gate = new MaintenanceGate($this->dir);
        $gate->enable('Updating', 30, [], 1_000_000);

        $this->assertNotNull($gate->blockingState('203.0.113.9', 1_000_000 + 1799));
        $this->assertNull($gate->blockingState('203.0.113.9', 1_000_000 + 1800));
    }

    public function testDisableRemovesTheFlag(): void
    {
        $gate = new MaintenanceGate($this->dir);
        $gate->enable('Updating');
        $gate->disable();

        $this->assertNull($gate->blockingState('203.0.113.9'));
    }

    public function testCorruptFlagFileIsIgnoredRatherThanTakingTheSiteDown(): void
    {
        file_put_contents($this->dir . '/var/maintenance.json', '{not json');

        $this->assertNull((new MaintenanceGate($this->dir))->blockingState('203.0.113.9'));
    }

    /** The installer writes the flag without this class (bash + php -r), so its exact JSON shape is part of the contract. */
    public function testReadsTheFlagTheInstallerWrites(): void
    {
        $now = time();
        file_put_contents($this->dir . '/var/maintenance.json', json_encode([
            'message' => 'Updating to 1.2.3', 'since' => $now, 'expires' => $now + 1800, 'allow_ips' => ['198.51.100.7'],
        ]));
        $gate = new MaintenanceGate($this->dir);

        $this->assertSame('Updating to 1.2.3', $gate->blockingState('203.0.113.9')['message']);
        $this->assertNull($gate->blockingState('198.51.100.7'));
    }

    public function testHtmlResponseIs503WithRetryAfterAndEscapesTheMessage(): void
    {
        $gate = new MaintenanceGate($this->dir);
        $gate->enable('<b>Updating</b>');

        $response = $gate->response($gate->state(), false);

        $this->assertSame(503, $response['status']);
        $this->assertSame('30', $response['headers']['Retry-After']);
        $this->assertStringContainsString('&lt;b&gt;Updating&lt;/b&gt;', $response['body']);
        $this->assertStringNotContainsString('<b>Updating</b>', $response['body']);
    }

    public function testJsonResponseForApiAndWebhookCallers(): void
    {
        $gate = new MaintenanceGate($this->dir);
        $gate->enable('Updating');

        $response = $gate->response($gate->state(), true);

        $this->assertSame(503, $response['status']);
        $this->assertSame('application/json', $response['headers']['Content-Type']);
        $this->assertSame('maintenance', json_decode($response['body'], true)['status']);
    }

    /** public/index.php requires the gate directly, before any autoloader exists, and must stop a blocked request. */
    public function testFrontControllerAnswers503BeforeTheKernelBoots(): void
    {
        $root = dirname(__DIR__, 3);
        (new MaintenanceGate($this->dir))->enable('Updating');
        $script = <<<'PHP'
            require $argv[1] . '/src/Maintenance/MaintenanceGate.php';
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            (new App\Maintenance\MaintenanceGate($argv[2]))->run();
            echo 'app would run';
            PHP;
        exec(sprintf('%s -r %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($root), escapeshellarg($this->dir)), $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Back shortly', $output);
        $this->assertStringNotContainsString('app would run', $output);
        $this->assertStringContainsString('MaintenanceGate', file_get_contents($root . '/public/index.php'));
    }
}
