<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The one-gate principle for the Htaccess Lock (ADR-062), enforced structurally: validation, the lockout
 * guard, the .htaccess write, the audit row and the change event live in HtaccessLockGate and nowhere else,
 * so the admin page, the API and the console command cannot grow their own hand-rolled copy and drift.
 *
 * If this fails, don't add an exception — route the new caller through HtaccessLockGate (add a gate method if
 * the gate cannot yet express what you need).
 */
final class HtaccessLockGateArchitectureTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    /** What only code inside src/Htaccess/ may touch. */
    private const INTERNALS = [
        'HtaccessLockManager',
        'HtaccessFile',
        'HtaccessLockValidator',
        'HtaccessLockRenderer',
        'HtaccessLockSelfTest',   // not ...Report, which is a plain value the gate hands out
        'HtaccessProbeInterface',
        'CurlLoopbackProbe',
        'HtaccessLockMutex',      // taking it around a gate call would deadlock: the gate takes it itself (issue #44)
    ];

    /** @return array<string,string> relative path => source */
    private function sources(bool $insideHtaccess): array
    {
        $root = realpath(self::SRC);
        self::assertNotFalse($root);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (str_starts_with($relative, 'Htaccess/') === $insideHtaccess) {
                $found[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }

    public function testNothingOutsideTheHtaccessNamespaceTouchesTheGatesInternals(): void
    {
        $outside = $this->sources(false);
        self::assertGreaterThan(50, \count($outside), 'the scan must actually be reading src/');

        foreach ($outside as $path => $source) {
            foreach (self::INTERNALS as $internal) {
                self::assertDoesNotMatchRegularExpression(
                    '/\b' . $internal . '\b(?!Report)/',
                    $source,
                    "$path reaches past the gate to $internal. Use App\\Htaccess\\HtaccessLockGate (ADR-062).",
                );
            }
        }
    }

    public function testNothingOutsideTheGateWritesTheLocksAuditActionsOrReadsItsConfigKeys(): void
    {
        foreach ($this->sources(false) as $path => $source) {
            self::assertStringNotContainsString('admin.htaccess_lock_', $source, "$path writes/reads a lock audit action by hand; only HtaccessLockGate audits the lock.");
            self::assertStringNotContainsString('htaccess_lock.', $source, "$path reads/writes a lock config key directly; only the manager (behind the gate) does.");
        }
    }

    public function testWithinTheNamespaceOnlyTheGateChangesThePolicyAuditsOrDispatches(): void
    {
        $inside = $this->sources(true);
        self::assertArrayHasKey('Htaccess/HtaccessLockGate.php', $inside);

        foreach ($inside as $path => $source) {
            if ($path === 'Htaccess/HtaccessLockGate.php') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/->\s*save\(|->\s*storeLastTest\(/', $source, "$path changes the stored policy; only the gate may (the manager merely defines save()).");
            self::assertStringNotContainsString('AuditLogger', $source, "$path audits; only the gate may.");
            self::assertStringNotContainsString('dispatch(', $source, "$path dispatches the change event; only the gate may.");
        }
    }

    public function testEveryAdapterGoesThroughTheGate(): void
    {
        $adapters = [
            'Controller/AdminHtaccessLockController.php',
            'Controller/Api/AdminApiHtaccessLockController.php',
            'Command/DisableHtaccessLockCommand.php',
        ];
        $outside = $this->sources(false);

        foreach ($adapters as $adapter) {
            self::assertArrayHasKey($adapter, $outside, "$adapter moved or was deleted — update this list.");
            self::assertStringContainsString('HtaccessLockGate', $outside[$adapter], "$adapter must use the gate.");
        }

        // ...and any other class that mentions the lock at all is on the list (a fourth adapter must be added here on purpose).
        foreach ($outside as $path => $source) {
            if (str_contains($source, 'HtaccessLockGate') && !\in_array($path, $adapters, true)) {
                self::fail("$path uses HtaccessLockGate but is not a listed adapter; add it to this test so the one-gate rule keeps covering it.");
            }
        }
    }
}
