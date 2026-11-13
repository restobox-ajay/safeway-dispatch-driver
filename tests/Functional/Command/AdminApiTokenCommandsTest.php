<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Admin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #39: admin API tokens can be issued with an expiry, listed (with the last 6 characters so you can
 * tell them apart) and revoked from the shell — one by id, or all of an admin's.
 */
final class AdminApiTokenCommandsTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['tokcli-a@example.com', 'tokcli-b@example.com'] as $email) {
            $admin = new Admin();
            $admin->setEmail($email);
            $admin->setName('Token CLI');
            $admin->setPassword('x');
            $admin->setRoles(['ROLE_ADMIN']);
            $em->persist($admin);
        }
        $em->flush();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement('DELETE FROM admin_access_tokens');
        $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'tokcli-%@example.com'");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.api_token_%'");
    }

    private function console(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input);

        return $tester;
    }

    /** @return array{0:string,1:CommandTester} plaintext + tester */
    private function create(array $input = []): array
    {
        $tester = $this->console('app:admin:create-api-token', $input + ['--email' => 'tokcli-a@example.com']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/store it now\): ([0-9a-f]{64})/', $tester->getDisplay());
        preg_match('/store it now\): ([0-9a-f]{64})/', $tester->getDisplay(), $m);

        return [$m[1], $tester];
    }

    /** @return array<string,mixed> */
    private function row(string $plaintext): array
    {
        return $this->conn->fetchAssociative('SELECT * FROM admin_access_tokens WHERE token_hash = ?', [hash('sha256', $plaintext)]) ?: [];
    }

    public function testANewTokenExpiresIn365DaysByDefaultAndRecordsItsLastSixCharacters(): void
    {
        [$plaintext, $tester] = $this->create();
        $row = $this->row($plaintext);

        self::assertSame(substr($plaintext, -6), $row['token_hint']);
        self::assertStringContainsString('…' . substr($plaintext, -6), $tester->getDisplay());
        $expires = new \DateTimeImmutable((string) $row['expires_at']);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+365 days'))->getTimestamp(), $expires->getTimestamp(), 120);
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_create' AND actor = 'console'"));
    }

    public function testExpiryCanBeChosenOrDeliberatelyTurnedOff(): void
    {
        [$short] = $this->create(['--expires-in-days' => '7']);
        $expires = new \DateTimeImmutable((string) $this->row($short)['expires_at']);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), $expires->getTimestamp(), 120);

        [$forever] = $this->create(['--no-expiry' => true]);
        self::assertNull($this->row($forever)['expires_at']);

        foreach (['0', '-3', 'abc', '3651'] as $bad) {
            $tester = $this->console('app:admin:create-api-token', ['--email' => 'tokcli-a@example.com', '--expires-in-days' => $bad]);
            self::assertSame(1, $tester->getStatusCode(), "--expires-in-days=$bad must be refused");
        }
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM admin_access_tokens'));
    }

    public function testListShowsOwnerNameHintAndStatusButNeverASecret(): void
    {
        [$plaintext] = $this->create(['--name' => 'CI deploy']);
        $this->conn->executeStatement("INSERT INTO admin_access_tokens (admin_id, name, token_hash, created_at) SELECT id, 'legacy', 'legacyhash', '2026-01-01 00:00:00' FROM admin WHERE email = 'tokcli-b@example.com'");

        $display = $this->console('app:admin:list-api-tokens')->getDisplay();

        self::assertStringContainsString('tokcli-a@example.com', $display);
        self::assertStringContainsString('CI deploy', $display);
        self::assertStringContainsString('…' . substr($plaintext, -6), $display);
        self::assertStringContainsString('(not recorded)', $display, 'tokens issued before hints existed say so');
        self::assertStringContainsString('active', $display);
        self::assertStringNotContainsString(substr($plaintext, 0, 20), $display);
        self::assertStringNotContainsString(hash('sha256', $plaintext), $display);

        $onlyB = $this->console('app:admin:list-api-tokens', ['--email' => 'tokcli-b@example.com'])->getDisplay();
        self::assertStringContainsString('legacy', $onlyB);
        self::assertStringNotContainsString('CI deploy', $onlyB);
    }

    public function testRevokeById(): void
    {
        [$plaintext] = $this->create();
        $id = (int) $this->row($plaintext)['id'];

        $tester = $this->console('app:admin:revoke-api-token', ['--id' => (string) $id]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotNull($this->row($plaintext)['revoked_at']);
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_revoke' AND actor = 'console'"));
        self::assertStringContainsString('revoked', $this->console('app:admin:list-api-tokens')->getDisplay());

        self::assertStringContainsString('already revoked', $this->console('app:admin:revoke-api-token', ['--id' => (string) $id])->getDisplay());
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_revoke'"), 'a no-op revoke is not audited again');
        self::assertSame(1, $this->console('app:admin:revoke-api-token', ['--id' => '999999'])->getStatusCode());
    }

    public function testRevokeAllOfOneAdminLeavesOtherAdminsAlone(): void
    {
        [$a1] = $this->create();
        [$a2] = $this->create();
        $b = $this->console('app:admin:create-api-token', ['--email' => 'tokcli-b@example.com']);
        preg_match('/store it now\): ([0-9a-f]{64})/', $b->getDisplay(), $m);

        $tester = $this->console('app:admin:revoke-api-token', ['--email' => 'tokcli-a@example.com', '--all' => true]);

        self::assertStringContainsString('Revoked 2 token(s)', $tester->getDisplay());
        self::assertNotNull($this->row($a1)['revoked_at']);
        self::assertNotNull($this->row($a2)['revoked_at']);
        self::assertNull($this->row($m[1])['revoked_at']);
    }

    public function testRevokeRefusesAmbiguousOrMissingSelectors(): void
    {
        foreach ([[], ['--email' => 'tokcli-a@example.com'], ['--all' => true], ['--id' => '1', '--email' => 'tokcli-a@example.com', '--all' => true], ['--id' => '1', '--email' => 'tokcli-a@example.com'], ['--id' => '1', '--all' => true]] as $input) {
            self::assertSame(1, $this->console('app:admin:revoke-api-token', $input)->getStatusCode(), json_encode($input));
        }
    }
}
