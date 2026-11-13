<?php

declare(strict_types=1);

namespace App\Tests\Functional\Meta;

use App\Meta\AdrTripwire;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-139 — the teeth behind the ADR tripwire.
 *
 * The advisory runner (bin/adr-tripwire.php) never fails the build (AC5: human-review flags, not a
 * gate). This test IS the gate: it asserts the tripwire finds ZERO flags on the real ledger, so the
 * discipline actually holds — every ADR in .agent/DECISIONS.md is tagged rationale|verifiable and
 * every verifiable ADR names a test file (and any repo path it claims) that really exists. Add an
 * untagged ADR, or cite a test that is later deleted/renamed, and this goes red.
 *
 * The second test proves the tripwire is not a no-op: fed a deliberately-broken ledger it produces
 * exactly the expected flags.
 */
final class AdrTripwireTest extends TestCase
{
    private function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testRealLedgerPassesTheTripwireWithNoFlags(): void
    {
        $flags = (new AdrTripwire($this->projectDir()))->flags();

        $this->assertSame(
            [],
            $flags,
            "The ADR tripwire flagged the real ledger — every ADR must be tagged rationale|verifiable "
            . "and every verifiable ADR must name an existing test:\n  - " . implode("\n  - ", $flags)
        );
    }

    public function testTripwireHasTeeth_flagsUntaggedMissingAndTestlessAdrs(): void
    {
        $tmp = sys_get_temp_dir() . '/adr-tripwire-' . bin2hex(random_bytes(6));
        @mkdir($tmp . '/.agent', 0777, true);
        @mkdir($tmp . '/tests', 0777, true);
        // A real test file the "good" ADR can point at.
        file_put_contents($tmp . '/tests/RealTest.php', "<?php\n");

        file_put_contents($tmp . '/.agent/DECISIONS.md', <<<'MD'
            # Architecture & Product Decisions

            ## ADR-001: Untagged decision
            **Decision:** Something with no Tripwire tag.

            ## ADR-002: Names a missing test
            **Tripwire:** verifiable. Verified by `tests/DoesNotExist.php`.
            **Decision:** X.

            ## ADR-003: Verifiable but names no test
            **Tripwire:** verifiable — but forgot to name a test.
            **Decision:** X.

            ## ADR-004: Honest rationale
            **Tripwire:** rationale (prose-only).
            **Decision:** X.

            ## ADR-005: Good verifiable
            **Tripwire:** verifiable. Verified by `tests/RealTest.php`.
            **Decision:** X.
            MD);

        try {
            $flags = (new AdrTripwire($tmp))->flags();

            $joined = implode("\n", $flags);
            $this->assertStringContainsString('ADR-001: no **Tripwire:** tag', $joined);
            $this->assertStringContainsString('ADR-002: names test `tests/DoesNotExist.php`', $joined);
            $this->assertStringContainsString('ADR-003: verifiable but names no test', $joined);
            // The honest rationale and the good verifiable must NOT be flagged.
            $this->assertStringNotContainsString('ADR-004', $joined);
            $this->assertStringNotContainsString('ADR-005', $joined);
            $this->assertCount(3, $flags, "Expected exactly 3 flags, got:\n  - " . implode("\n  - ", $flags));
        } finally {
            @unlink($tmp . '/.agent/DECISIONS.md');
            @unlink($tmp . '/tests/RealTest.php');
            @rmdir($tmp . '/.agent');
            @rmdir($tmp . '/tests');
            @rmdir($tmp);
        }
    }
}
