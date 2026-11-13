<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Htaccess\HtaccessLockManager;
use App\Htaccess\HtaccessLockSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shell is the way back in if the lock locks an admin out of the panel that controls it (ADR-059).
 */
final class DisableHtaccessLockCommandTest extends KernelTestCase
{
    private const ORIGINAL = "RewriteEngine On\nRewriteRule ^ index.php [L]\n";

    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess';
        $this->wipe();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        parent::tearDown();
    }

    private function wipe(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess.lock');
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess-gate.lock');
    }

    public function testDisableRemovesOnlyTheBlockAndRecordsTheLockAsOff(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $manager = self::getContainer()->get(HtaccessLockManager::class);
        $manager->save(new HtaccessLockSettings(true, ['203.0.113.0/24'], [], 404, ''));
        self::assertStringContainsString('app/htaccess-lock', (string) file_get_contents($this->dir . '/.htaccess'));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:htaccess-lock:disable'));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
        self::assertFalse($manager->settings()->enabled);
        self::assertSame(['203.0.113.0/24'], $manager->settings()->ips, 'the saved whitelist is kept for next time');
    }

    public function testDisableGoesThroughTheGateSoItIsAuditedLikeEveryOtherChange(): void
    {
        $manager = self::getContainer()->get(HtaccessLockManager::class);
        $manager->save(new HtaccessLockSettings(true, ['203.0.113.0/24'], [], 404, ''));
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");

        (new CommandTester((new Application(self::$kernel))->find('app:htaccess-lock:disable')))->execute([]);

        $rows = $conn->fetchAllAssociative("SELECT action, outcome, actor FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
        self::assertSame([['action' => 'admin.htaccess_lock_disable', 'outcome' => 'success', 'actor' => 'console']], $rows);
        $conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
    }

    public function testDisableIsHarmlessWhenThereIsNothingToRemove(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:htaccess-lock:disable'));

        self::assertSame(0, $tester->execute([]));
        self::assertFileDoesNotExist($this->dir . '/.htaccess');
    }

    public function testDisableFailsLoudlyOnAFileWithUnbalancedMarkers(): void
    {
        $broken = "# ###> app/htaccess-lock ###\nRequire ip 1.2.3.4\n";
        file_put_contents($this->dir . '/.htaccess', $broken);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:htaccess-lock:disable'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('unbalanced', $tester->getDisplay());
        self::assertSame($broken, file_get_contents($this->dir . '/.htaccess'));
    }
}
