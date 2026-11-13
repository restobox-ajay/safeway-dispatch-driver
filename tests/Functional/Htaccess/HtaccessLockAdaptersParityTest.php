<?php

declare(strict_types=1);

namespace App\Tests\Functional\Htaccess;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A whitelist action through the admin page and the same action through the API must be indistinguishable
 * downstream: same .htaccess, same stored policy, same audit row, same refusal (ADR-062). This is the
 * behavioural twin of HtaccessLockGateArchitectureTest — that one forbids a second code path, this one
 * would notice if two paths ever produced different results anyway.
 */
final class HtaccessLockAdaptersParityTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $dir;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->dir = self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess';

        $this->reset();
        $this->loginAsEnrolledTechSupport('parity-ts@example.com');

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'parity-ts@example.com'");
        $this->token = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id' => $adminId,
            'name' => 'parity',
            'token_hash' => hash('sha256', $this->token),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->reset();
        $this->conn->executeStatement('DELETE FROM admin_access_tokens');
        $this->conn->executeStatement("DELETE FROM admin WHERE email = 'parity-ts@example.com'");
        $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $this->conn->executeStatement('DELETE FROM admin_sessions');
        parent::tearDown();
    }

    /** Clean slate between the two runs of a comparison: empty policy, pristine .htaccess, no audit rows. */
    private function reset(): void
    {
        $this->conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/.htaccess', "RewriteEngine On\n");
    }

    /** @param array<string,mixed> $fields */
    private function viaWeb(array $fields): int
    {
        $this->client->request('GET', '/admin/htaccess-lock');
        $this->client->submitForm('Save', $fields);

        return $this->client->getResponse()->getStatusCode();
    }

    private function viaApi(string $method, string $url, ?array $json = null): int
    {
        $this->client->request($method, $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json'], $json !== null ? json_encode($json) : null);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{file:string,config:array<string,string>,audit:list<array{action:string,outcome:string,context:?string}>} */
    private function state(): array
    {
        $config = $this->conn->fetchAllKeyValue("SELECT config_key, config_value FROM config WHERE config_key LIKE 'htaccess_lock.%' AND config_key <> 'htaccess_lock.last_test' ORDER BY config_key");

        return [
            'file' => (string) file_get_contents($this->dir . '/.htaccess'),
            'config' => $config,
            'audit' => $this->conn->fetchAllAssociative("SELECT action, outcome, context FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%' AND actor = 'parity-ts@example.com' ORDER BY id"),
        ];
    }

    public function testTheSameChangeThroughThePageAndThroughTheApiLeavesIdenticalFileConfigAndAuditTrail(): void
    {
        $this->viaWeb(['enabled' => '1', 'ips' => "127.0.0.1\n203.0.113.0/24", 'exempt_paths' => '/health', 'status_code' => '404', 'error_file' => '']);
        $web = $this->state();
        self::assertStringContainsString('app/htaccess-lock', $web['file']);
        self::assertSame('success', $web['audit'][0]['outcome']);

        $this->reset();

        // The API expresses the same change as its finer-grained calls; the result must be the same policy and file.
        self::assertSame(201, $this->viaApi('POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1']));
        self::assertSame(201, $this->viaApi('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.0/24']));
        self::assertSame(201, $this->viaApi('POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/health']));
        self::assertSame(200, $this->viaApi('POST', '/admin-api/htaccess-lock/enable'));
        $api = $this->state();

        self::assertSame($web['file'], $api['file'], 'the same .htaccess');
        self::assertSame($web['config'], $api['config'], 'the same stored policy');
        self::assertSame(['admin.htaccess_lock_ip_add', 'admin.htaccess_lock_ip_add', 'admin.htaccess_lock_exempt_add', 'admin.htaccess_lock_enable'], array_column($api['audit'], 'action'));
        self::assertSame(['success', 'success', 'success', 'success'], array_column($api['audit'], 'outcome'));
        self::assertSame($web['audit'][0]['context'], $api['audit'][3]['context'], 'the audit context describes the resulting policy identically');
    }

    public function testTheSameRefusalThroughThePageAndThroughTheApiIsTheSameRefusalAuditedTheSameWay(): void
    {
        // A whitelist that does not cover the caller (127.0.0.1) must not be switchable on, from either door.
        self::assertSame(422, $this->viaWeb(['enabled' => '1', 'ips' => '203.0.113.9', 'exempt_paths' => '', 'status_code' => '404', 'error_file' => '']));
        $webMessage = trim($this->client->getCrawler()->filter('.error, .errors, .alert, li')->reduce(static fn ($n) => str_contains($n->text(), 'lock you out'))->first()->text());
        $web = $this->state();

        $this->reset();

        self::assertSame(201, $this->viaApi('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.9']));
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
        self::assertSame(422, $this->viaApi('POST', '/admin-api/htaccess-lock/enable'));
        $apiMessage = json_decode((string) $this->client->getResponse()->getContent(), true)['error'];
        $api = $this->state();

        self::assertSame('RewriteEngine On' . "\n", $web['file']);
        self::assertSame($web['file'], $api['file'], 'neither door wrote anything');
        self::assertStringContainsString($apiMessage, $webMessage, 'the very same message reaches both callers');
        self::assertSame('failure', $web['audit'][0]['outcome']);
        self::assertSame('failure', $api['audit'][0]['outcome']);
        self::assertSame($web['audit'][0]['context'], $api['audit'][0]['context'], 'refusals are audited identically');
    }
}
