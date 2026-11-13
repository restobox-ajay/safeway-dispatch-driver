<?php

declare(strict_types=1);

namespace App\Tests\Support\Extension;

use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Extension;

/**
 * Boots a real PHP built-in web server for the Acceptance suite so phpBrowser
 * exercises the application over genuine HTTP. The server runs with
 * APP_ENV=acceptance (real PdoSessionHandler) and is shut down after the suite.
 *
 * Registered globally in codeception.yml but only acts for the Acceptance suite,
 * leaving the PHPUnit-driven and (empty) Functional runs untouched.
 */
class PhpBuiltinServerExtension extends Extension
{
    /** @var array<string, string> */
    public static array $events = [
        Events::SUITE_BEFORE => 'startServer',
        Events::SUITE_AFTER => 'stopServer',
    ];

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function startServer(SuiteEvent $event): void
    {
        if (!$this->isAcceptanceSuite($event)) {
            return;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 8899);
        $docroot = (string) ($this->config['docroot'] ?? 'public');
        $env = (string) ($this->config['env'] ?? 'acceptance');

        $projectDir = \dirname(__DIR__, 3);
        $router = $projectDir . '/' . $docroot . '/index.php';

        $command = [
            PHP_BINARY,
            // Include environment variables in $_SERVER so the Symfony runtime
            // reads APP_ENV/APP_DEBUG from the child process environment.
            '-d',
            'variables_order=EGPCS',
            '-S',
            $host . ':' . $port,
            '-t',
            $projectDir . '/' . $docroot,
            $router,
        ];

        $childEnv = $_SERVER;
        $childEnv['APP_ENV'] = $env;
        // The built-in server must not run in CLI test mode.
        unset($childEnv['APP_DEBUG']);
        $childEnv['APP_DEBUG'] = '1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $projectDir . '/var/acceptance-server.log', 'a'],
            2 => ['file', $projectDir . '/var/acceptance-server.log', 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $this->pipes, $projectDir, $this->filterEnv($childEnv));

        if (!\is_resource($this->process)) {
            throw new \RuntimeException('Unable to start the PHP built-in server for acceptance tests.');
        }

        $this->waitForServer($host, $port);
        $this->writeln(sprintf('PhpBuiltinServer: listening on http://%s:%d (APP_ENV=%s)', $host, $port, $env));
    }

    public function stopServer(SuiteEvent $event): void
    {
        if (!$this->isAcceptanceSuite($event) || !\is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process, defined('SIGTERM') ? SIGTERM : 15);

        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        proc_close($this->process);
        $this->process = null;
        $this->writeln('PhpBuiltinServer: stopped');
    }

    private function isAcceptanceSuite(SuiteEvent $event): bool
    {
        // getBaseName() returns the namespaced suite id, e.g. "App\Tests.Acceptance".
        return str_ends_with(strtolower($event->getSuite()->getBaseName()), 'acceptance');
    }

    /**
     * @param array<string, mixed> $env
     * @return array<string, string>
     */
    private function filterEnv(array $env): array
    {
        $filtered = [];
        foreach ($env as $key => $value) {
            if (\is_scalar($value)) {
                $filtered[$key] = (string) $value;
            }
        }

        return $filtered;
    }

    private function waitForServer(string $host, int $port): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 0.5);
            if (\is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new \RuntimeException(sprintf('PHP built-in server did not become ready on %s:%d.', $host, $port));
    }
}
