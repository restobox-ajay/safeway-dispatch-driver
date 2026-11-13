<?php

declare(strict_types=1);

namespace App\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guards the release/installer scripts (ADR-067..071); the behavioural end-to-end run is tests/installer/e2e.sh. */
final class ReleaseScriptsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        yield 'installer' => ['bin/rbx-install.sh'];
        yield 'tarball builder' => ['bin/build-release.sh'];
        yield 'token baker' => ['bin/bake-installer.sh'];
        yield 'asset pruner' => ['bin/prune-release-assets.sh'];
        yield 'e2e test' => ['tests/installer/e2e.sh'];
    }

    #[DataProvider('scripts')]
    public function testScriptHasValidBashSyntax(string $script): void
    {
        exec('bash -n ' . escapeshellarg(self::root() . '/' . $script) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    /** Release commits must not carry semantic-release's default identity (it matches an unrelated public GitHub account). */
    public function testReleaseWorkflowSetsGithubActionsCommitIdentity(): void
    {
        $workflow = (string) file_get_contents(self::root() . '/.github/workflows/release.yml');

        foreach (['GIT_AUTHOR_NAME', 'GIT_COMMITTER_NAME'] as $name) {
            $this->assertStringContainsString($name . ': github-actions[bot]', $workflow);
        }
        foreach (['GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_EMAIL'] as $email) {
            $this->assertStringContainsString($email . ': 41898282+github-actions[bot]@users.noreply.github.com', $workflow);
        }
    }

    /** ADR-071: the only token ever committed is a fine-grained read-only PAT; empty is fine, a classic/other token never is. */
    public function testBakedTokenIsEmptyOrAFineGrainedToken(): void
    {
        $this->assertSame(1, preg_match('/^BAKED_TOKEN="([^"]*)"$/m', (string) file_get_contents(self::root() . '/bin/rbx-install.sh'), $m));
        $this->assertMatchesRegularExpression('/^(github_pat_[A-Za-z0-9_]+)?$/', $m[1]);
    }

    /** What the tarball keeps, judged by the very pattern build-release.sh applies to `git ls-files`. */
    public function testTarballKeepsRuntimeFilesAndDropsDevOnes(): void
    {
        $this->assertSame(1, preg_match("/grep -zvE '([^']+)'/", (string) file_get_contents(self::root() . '/bin/build-release.sh'), $m));
        $excluded = static fn (string $path): bool => preg_match('#' . str_replace('#', '\#', $m[1]) . '#', $path) === 1;

        foreach (['docs/api/openapi.yaml', 'bin/console', 'bin/rbx-install.sh', 'config/deploy/public.htaccess', 'public/index.php',
            'src/Maintenance/MaintenanceGate.php', 'composer.lock', '.env', 'tools/phpliteadmin.php', 'migrations/'] as $kept) {
            $this->assertFalse($excluded($kept), "$kept must ship in the tarball");
        }
        foreach (['tests/Unit/x.php', '.agent/DECISIONS.md', '.github/workflows/release.yml', 'CLAUDE.md', 'docs/SPEC.md',
            'docs/bundles/auth-pat-bundle.md', 'bin/verify-fast.sh', 'bin/build-release.sh', 'package.json', '.env.test',
            'public/testing/location-pings.html'] as $dropped) {
            $this->assertTrue($excluded($dropped), "$dropped must not ship in the tarball");
        }
    }

    /** The admin API Docs page reads this file at runtime, so the tarball must carry it. */
    public function testOpenApiSpecServedAtRuntimeExists(): void
    {
        $this->assertFileExists(self::root() . '/docs/api/openapi.yaml');
        $this->assertStringContainsString('docs/api/openapi.yaml', (string) file_get_contents(self::root() . '/config/services.yaml'));
    }
}
