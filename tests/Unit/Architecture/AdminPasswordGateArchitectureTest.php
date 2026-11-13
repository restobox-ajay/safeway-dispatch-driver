<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The one-gate principle for changing an existing admin's password (ADR-073), enforced structurally: the
 * password write, the reset-token consumption, the audit rows and the revocations (sessions, DB console, pending
 * links) live in AdminPassword\AdminPasswordGate and nowhere else, so the reset link, "change my password" and
 * tech support's "set password" cannot grow a hand-rolled copy and drift.
 *
 * If this fails, don't add an exception — route the new caller through AdminPasswordGate (add a source to it
 * if it cannot yet express what you need).
 */
final class AdminPasswordGateArchitectureTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';
    private const GATE = 'AdminPassword/AdminPasswordGate.php';

    /** Classes that CREATE an admin (setting its first password) — a creation is not a change. */
    private const CREATORS = [
        'Command/CreateSuperAdminCommand.php',
        'Command/CreateTechSupportCommand.php',
        'Controller/AdminAdminManagementController.php',   // the "new admin" form
    ];

    /** @return array<string,string> relative path => source */
    private function sources(): array
    {
        $root = realpath(self::SRC);
        self::assertNotFalse($root);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $found[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }

    public function testOnlyTheGateChangesAnAdminsPassword(): void
    {
        $sources = $this->sources();
        self::assertArrayHasKey(self::GATE, $sources);
        self::assertGreaterThan(50, \count($sources), 'the scan must actually be reading src/');

        foreach ($sources as $path => $source) {
            if ($path === self::GATE || str_starts_with($path, 'Entity/')) {
                continue;
            }
            // Only admin-touching files matter: User password flows are a different realm (ADR-003).
            if (!preg_match('/\bAdmin\b/', $source)) {
                continue;
            }
            // Any receiver, any variable name: a creator may set exactly one (the new account's first), nobody else any.
            $writes = preg_match_all('/->\s*setPassword\s*\(/', $source);
            if (\in_array($path, self::CREATORS, true)) {
                self::assertStringContainsString('new Admin()', $source, "$path is listed as an admin creator but does not create one.");
                self::assertSame(1, $writes, "$path may set only the new admin's first password. Change an existing one through AdminPassword\\AdminPasswordGate (ADR-073).");
                continue;
            }
            self::assertSame(0, $writes, "$path sets an admin's password by hand. Use AdminPassword\\AdminPasswordGate (ADR-073).");
        }
    }

    public function testOnlyTheGateAuditsPasswordChangesOrConsumesResetLinks(): void
    {
        // Who may even name an admin reset token: its own storage/maintenance classes, the sender (which
        // creates links) and the gate (which consumes them).
        $mayHandleTokens = [
            self::GATE,
            'Entity/AdminPasswordResetToken.php',
            'Repository/AdminPasswordResetTokenRepository.php',
            'Repository/PasswordResetTokenRepositoryTrait.php',
            'Prune/AdminPasswordResetTokenPruner.php',
            'Service/AdminPasswordResetService.php',
            'Security/RecoveryTokenInvalidator.php',
        ];

        foreach ($this->sources() as $path => $source) {
            if ($path === self::GATE) {
                continue;
            }
            self::assertStringNotContainsString("'admin.password_reset'", $source, "$path writes the password-reset audit action by hand; only the gate audits it.");
            self::assertStringNotContainsString("'admin.password_change'", $source, "$path writes the password-change audit action by hand; only the gate audits it.");
            self::assertStringNotContainsString("'admin.password_set'", $source, "$path writes the password-set audit action by hand; only the gate audits it.");
            if (!str_starts_with($path, 'Entity/') && !\in_array($path, $mayHandleTokens, true)) {
                self::assertStringNotContainsString('AdminPasswordResetToken', $source, "$path handles admin reset links; only the gate consumes them (it may be listed in \$mayHandleTokens if it only creates/prunes them).");
            }
        }
    }

    public function testEveryAdapterGoesThroughTheGate(): void
    {
        $adapters = [
            'Controller/AdminPasswordResetController.php',
            'Controller/AdminAccountController.php',
            'Controller/AdminAdminManagementController.php',
        ];
        $sources = $this->sources();

        foreach ($adapters as $adapter) {
            self::assertArrayHasKey($adapter, $sources, "$adapter moved or was deleted — update this list.");
            self::assertStringContainsString('AdminPasswordGate', $sources[$adapter], "$adapter must use the gate.");
        }

        foreach ($sources as $path => $source) {
            if ($path !== self::GATE && !str_starts_with($path, 'AdminPassword/') && str_contains($source, 'AdminPasswordGate') && !\in_array($path, $adapters, true)) {
                self::fail("$path uses AdminPasswordGate but is not a listed adapter; add it to this test so the one-gate rule keeps covering it.");
            }
        }
    }

    public function testAdaptersDoNotReachPastTheGateForTheRevocations(): void
    {
        // The reset page and the two forms must not revoke consoles/sessions themselves any more.
        $sources = $this->sources();
        foreach (['Controller/AdminPasswordResetController.php', 'Controller/Api/'] as $prefix) {
            foreach ($sources as $path => $source) {
                if (str_starts_with($path, $prefix) && str_contains($source, 'password')) {
                    self::assertStringNotContainsString('DbConsoleSessionRepository', $source, "$path revokes consoles; password changes revoke through the gate.");
                }
            }
        }
    }
}
