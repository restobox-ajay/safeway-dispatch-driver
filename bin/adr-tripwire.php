<?php

declare(strict_types=1);

/**
 * FEATURE-139 — ADR tripwire (advisory runner).
 *
 * Prints human-review flags for ADRs in .agent/DECISIONS.md that are untagged, verifiable-but-
 * name-no-test, or that name a test/repo path which does not exist. This is DELIBERATELY advisory:
 * it ALWAYS exits 0 and never gates the build. Per FEATURE-139 AC5 the teeth come from ADRs carrying
 * tests (enforced by tests/Functional/Meta/AdrTripwireTest.php), not from a script that opines.
 *
 * Surfaced by bin/verify-fast.sh as an informational step. Run standalone with:
 *   php bin/adr-tripwire.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Meta\AdrTripwire;

$flags = (new AdrTripwire(dirname(__DIR__)))->flags();

echo "=== ADR tripwire (advisory — human-review flags, non-gating) ===\n";

if ($flags === []) {
    echo "No flags: every ADR is tagged and every verifiable ADR names an existing test.\n";
    exit(0);
}

echo count($flags) . " flag(s) for human review:\n";
foreach ($flags as $flag) {
    echo "  - " . $flag . "\n";
}

// Advisory only: never fail the gate.
exit(0);
