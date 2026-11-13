<?php

declare(strict_types=1);

namespace App\Tests\Unit\Meta;

use PHPUnit\Framework\TestCase;

/**
 * FEATURE-127 (review C38) — Ledger-truth guard.
 *
 * A feature marked passes:true must not cite, as live evidence, a test method that no longer
 * exists. Commit 2030c94 (FEATURE-081) and later work (soft-delete FEATURE-110, ADR-022 invitation
 * resend, ADR-023 webhook Messenger migration) deleted or renamed test methods, but several older
 * features kept citing the old names — so passes:true stopped reflecting reality.
 *
 * This test parses `.agent/feature_list.json` and asserts that every PHPUnit-style `testXxx` name
 * cited in a feature's evidence or acceptance_criteria resolves to a real test method in tests/,
 * UNLESS the citation is an explicit historical note (e.g. "the prior testFoo was removed" or
 * "reconciled testFoo -> testBar") — those deliberately reference a gone test and are honest.
 *
 * Scope: PHPUnit `testXxx` methods only (the unambiguous convention). Codeception Cest scenarios
 * (camelCase, no `test` prefix) are reconciled manually and not machine-audited here.
 */
final class LedgerEvidenceAuditTest extends TestCase
{
    /**
     * Words that mark a cited name as a deliberate reference to a removed/renamed test rather than
     * a live "this test proves X" claim. Kept deliberately narrow so genuine drift is still caught.
     */
    private const HISTORICAL_MARKERS = [
        'reconcil', 'renam', 'removed', 'rewritten', 'consolidated', 'formerly',
        'previously', 'superseded', 'replaced', 'no longer', 'prior ', 'deleted',
    ];

    public function testEveryCitedTestMethodResolvesOrIsDocumentedAsRemoved(): void
    {
        $projectDir = \dirname(__DIR__, 3);
        $ledgerPath = $projectDir . '/.agent/feature_list.json';
        $this->assertFileExists($ledgerPath, 'feature_list.json ledger must exist');

        $ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, \JSON_THROW_ON_ERROR);
        $features = $ledger['features'] ?? [];
        $this->assertNotEmpty($features, 'ledger must contain features');

        $realMethods = $this->collectRealTestMethods($projectDir . '/tests');
        $this->assertNotEmpty($realMethods, 'must discover real test methods under tests/');

        $violations = [];
        foreach ($features as $feature) {
            $id = $feature['id'] ?? '(unknown)';
            $strings = array_merge(
                array_values($feature['evidence'] ?? []),
                array_values($feature['acceptance_criteria'] ?? []),
            );
            foreach ($strings as $text) {
                foreach ($this->citedTestNames($text) as [$name, $offset]) {
                    if (isset($realMethods[$name])) {
                        continue;
                    }
                    if ($this->isDocumentedHistoricalReference($text, $offset, \strlen($name))) {
                        continue;
                    }
                    $violations[] = sprintf('%s cites missing test method %s()', $id, $name);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Ledger evidence cites test methods that do not exist as live proof:\n  - "
            . implode("\n  - ", $violations)
        );
    }

    /**
     * @return array<string, true> real `testXxx` method names defined anywhere under tests/
     */
    private function collectRealTestMethods(string $testsDir): array
    {
        $methods = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/function\s+(test[A-Za-z0-9_]+)/', $src, $m)) {
                foreach ($m[1] as $name) {
                    $methods[$name] = true;
                }
            }
        }

        return $methods;
    }

    /**
     * @return list<array{0: string, 1: int}> [name, byte-offset] of each PHPUnit-style citation
     */
    private function citedTestNames(string $text): array
    {
        $out = [];
        if (preg_match_all('/\btest[A-Z][A-Za-z0-9_]+/', $text, $m, \PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$name, $offset]) {
                $out[] = [$name, $offset];
            }
        }

        return $out;
    }

    private function isDocumentedHistoricalReference(string $text, int $offset, int $length): bool
    {
        $start = max(0, $offset - 60);
        $context = strtolower(substr($text, $start, ($offset - $start) + $length + 40));
        foreach (self::HISTORICAL_MARKERS as $marker) {
            if (str_contains($context, $marker)) {
                return true;
            }
        }

        return false;
    }
}
