<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #17: if a bad admin IP whitelist locks every admin out of the panel, the shell is the way back in —
 * like app:htaccess-lock:disable for the Htaccess Lock. Clears the global list(s) only; per-user overrides stay.
 */
final class ClearIpWhitelistCommandTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->reset();
        foreach (['ip_whitelist.admin_ips' => '198.51.100.0/24', 'ip_whitelist.user_ips' => '203.0.113.0/24'] as $key => $value) {
            $this->conn->executeStatement('INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)', [$key, $value]);
        }
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        $this->conn->executeStatement("DELETE FROM config WHERE config_key IN ('ip_whitelist.admin_ips', 'ip_whitelist.user_ips')");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action = 'admin.ip_whitelist_clear'");
    }

    private function console(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ip-whitelist:clear'));
        $tester->execute($input);

        return $tester;
    }

    private function value(string $key): string
    {
        return (string) $this->conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);
    }

    public function testByDefaultItClearsOnlyTheAdminListAndIsAudited(): void
    {
        $tester = $this->console();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $this->value('ip_whitelist.admin_ips'));
        self::assertSame('203.0.113.0/24', $this->value('ip_whitelist.user_ips'));
        self::assertStringContainsString('198.51.100.0/24', $tester->getDisplay(), 'it shows what was removed, so it can be re-entered correctly');
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.ip_whitelist_clear' AND actor = 'console'"));
    }

    public function testItCanClearTheUserListOrBoth(): void
    {
        $this->console(['--scope' => 'user']);
        self::assertSame('198.51.100.0/24', $this->value('ip_whitelist.admin_ips'));
        self::assertSame('', $this->value('ip_whitelist.user_ips'));

        $this->conn->executeStatement("UPDATE config SET config_value = '203.0.113.0/24' WHERE config_key = 'ip_whitelist.user_ips'");
        // A real run is a fresh process; drop the entity the previous run left in this kernel's identity map.
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $this->console(['--scope' => 'all']);
        self::assertSame('', $this->value('ip_whitelist.admin_ips'));
        self::assertSame('', $this->value('ip_whitelist.user_ips'));
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        self::assertSame(1, $this->console(['--scope' => 'everyone'])->getStatusCode());
        self::assertSame('198.51.100.0/24', $this->value('ip_whitelist.admin_ips'));
    }
}
