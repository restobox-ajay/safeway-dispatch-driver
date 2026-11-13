<?php

declare(strict_types=1);

namespace App\Meta;

/**
 * FEATURE-139 — ADR tripwire.
 *
 * Parses `.agent/DECISIONS.md` and enforces the ADR discipline: every ADR must carry a
 * `**Tripwire:**` tag classifying it as `rationale` (prose-only) or `verifiable` (makes a
 * machine-checkable claim), and every `verifiable` ADR must NAME at least one test that goes red
 * if its claim stops being true. The tripwire then FLAGS:
 *   - any ADR with no `**Tripwire:**` tag,
 *   - a Tripwire tag that is neither rationale nor verifiable (malformed),
 *   - a `verifiable` ADR that names no test,
 *   - a named test file that does not exist,
 *   - a named repo path (src/…, config/…, bin/…, migrations/…) that is absent from the repo.
 *
 * Only the `**Tripwire:**` LINE of each ADR is parsed for references — never the prose Impact
 * lists — so the check is deterministic and does not false-flag on renamed files merely mentioned
 * in the discussion.
 *
 * This is a HUMAN-REVIEW tool, not a smart judge: it does not read or opine on the ADR's argument.
 * The advisory runner (`bin/adr-tripwire.php`) always exits 0. The teeth are the companion test
 * `tests/Functional/Meta/AdrTripwireTest.php`, which asserts this parser finds zero flags.
 */
final class AdrTripwire
{
    /** Backtick tokens starting with one of these prefixes are treated as repo-path claims. */
    private const REPO_PREFIXES = ['src/', 'config/', 'bin/', 'migrations/', 'templates/', '.env'];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return list<string> human-readable flag lines; empty means the ledger is clean.
     */
    public function flags(): array
    {
        $decisionsPath = $this->projectDir . '/.agent/DECISIONS.md';
        if (!is_file($decisionsPath)) {
            return ['DECISIONS.md not found at ' . $decisionsPath];
        }

        $flags = [];
        foreach ($this->sections((string) file_get_contents($decisionsPath)) as $adr => $body) {
            $tripwire = $this->tripwireLine($body);

            if ($tripwire === null) {
                $flags[] = sprintf('%s: no **Tripwire:** tag (must be rationale or verifiable).', $adr);
                continue;
            }

            $kind = $this->kind($tripwire);
            if ($kind === null) {
                $flags[] = sprintf('%s: **Tripwire:** tag is neither "rationale" nor "verifiable".', $adr);
                continue;
            }

            $refs = $this->backtickTokens($tripwire);
            $tests = array_values(array_filter($refs, static fn (string $r): bool => str_starts_with($r, 'tests/')));
            $repos = array_values(array_filter($refs, fn (string $r): bool => $this->isRepoRef($r)));

            if ($kind === 'verifiable' && $tests === []) {
                $flags[] = sprintf('%s: verifiable but names no test (add "Verified by `tests/…`").', $adr);
            }

            foreach ($tests as $test) {
                $file = explode('::', $test, 2)[0];
                if (!is_file($this->projectDir . '/' . $file)) {
                    $flags[] = sprintf('%s: names test `%s` but the file does not exist.', $adr, $file);
                }
            }

            foreach ($repos as $repo) {
                if (!file_exists($this->projectDir . '/' . $repo)) {
                    $flags[] = sprintf('%s: claims repo path `%s` but it is absent.', $adr, $repo);
                }
            }
        }

        return $flags;
    }

    /**
     * Split the document into ADR sections keyed by ADR id, capturing each heading's body up to the
     * next `## ADR-` heading.
     *
     * @return array<string, string>
     */
    private function sections(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $sections = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(ADR-\d+)\b/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] = '';
                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    private function tripwireLine(string $body): ?string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with(trim($line), '**Tripwire:**')) {
                return trim($line);
            }
        }

        return null;
    }

    private function kind(string $tripwireLine): ?string
    {
        // Only look at the classifier word right after the marker, so a "rationale" mentioned later
        // in an explanatory clause cannot be mistaken for the kind.
        $after = trim(substr($tripwireLine, \strlen('**Tripwire:**')));
        if (preg_match('/^verifiable\b/i', $after) === 1) {
            return 'verifiable';
        }
        if (preg_match('/^rationale\b/i', $after) === 1) {
            return 'rationale';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function backtickTokens(string $line): array
    {
        preg_match_all('/`([^`]+)`/', $line, $m);

        return $m[1] ?? [];
    }

    private function isRepoRef(string $ref): bool
    {
        foreach (self::REPO_PREFIXES as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
