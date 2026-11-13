<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Htaccess\CurlLoopbackProbe;
use App\Htaccess\HtaccessLockManager;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use App\Tests\Support\ScriptedHtaccessProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Htaccess Lock (ADR-059): a tech-support-only page that edits a managed block of the real .htaccess.
 * The file here is var/test-htaccess/.htaccess (config/services.yaml when@test) — never public/.htaccess —
 * and its directory is the docroot the error file is resolved against. PHPUnit runs no Apache, so a
 * scripted probe stands in for the web server; real enforcement was verified against Apache 2.4 by hand.
 */
final class HtaccessLockControllerTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const TS_SECRET = 'JBSWY3DPEHPK3PXP';
    private const ORIGINAL = "RewriteEngine On\nRewriteRule ^ index.php [L]\n";

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ScriptedHtaccessProbe $probe;
    private string $dir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one container for the whole test so the scripted probe set below survives between requests.
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dir = self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess';
        $this->probe = new ScriptedHtaccessProbe();
        self::getContainer()->set(CurlLoopbackProbe::class, $this->probe);

        $this->cleanup();
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/blocked.html', '<h1>Not found</h1>');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'htl-%@example.com'");
        $conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
        $conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $conn->executeStatement("DELETE FROM admin_sessions WHERE admin_id NOT IN (SELECT id FROM admin)");

        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }
        @rmdir($this->dir);
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess.lock');
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess-gate.lock');
        $this->em->clear();
    }

    private function loginAsTechSupport(): void
    {
        $admin = $this->createTestAdmin('htl-ts@example.com', 'HTL TS', roles: ['ROLE_TECH_SUPPORT']);
        $admin->setTotpSecret(self::TS_SECRET);
        $admin->setIsTotpEnabled(true);
        $this->em->flush();
        $this->em->clear();

        $this->loginAsAdmin('htl-ts@example.com', followRedirect: false);
        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect();
        $code = self::getContainer()->get(TotpService::class)->generateCode(self::TS_SECRET);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    private function htaccess(): string
    {
        return is_file($this->dir . '/.htaccess') ? (string) file_get_contents($this->dir . '/.htaccess') : '';
    }

    private function config(string $key): ?string
    {
        $v = $this->em->getConnection()->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);

        return $v === false ? null : (string) $v;
    }

    /** @param array<string,string> $fields */
    private function save(array $fields = []): void
    {
        $this->client->request('GET', '/admin/htaccess-lock');
        $this->client->submitForm('Save', $fields + [
            'enabled' => '1',
            'ips' => '127.0.0.1',
            'exempt_paths' => '',
            'status_code' => '404',
            'error_file' => '',
        ]);
    }

    public function testTechSupportSeesThePageWithTheirIp(): void
    {
        $this->loginAsTechSupport();

        $this->client->request('GET', '/admin/htaccess-lock');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Htaccess Lock');
        self::assertSelectorTextContains('strong', '127.0.0.1');
        self::assertSelectorExists('input[type=radio][name=enabled][value="1"]');
        self::assertSelectorExists('textarea[name=ips]');
        self::assertSelectorExists('textarea[name=exempt_paths]');
        self::assertSelectorExists('select[name=status_code] option[value="404"]');
        self::assertSelectorExists('input[name=error_file]');
    }

    public function testSuperadminIsForbidden(): void
    {
        $this->createTestAdmin('htl-super@example.com', 'HTL Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('htl-super@example.com');

        $this->client->request('GET', '/admin/htaccess-lock');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/htaccess-lock/save', ['enabled' => '1', 'ips' => '127.0.0.1', 'status_code' => '404']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/htaccess-lock/test');
        self::assertResponseStatusCodeSame(403);

        self::assertSame('', $this->htaccess(), 'a superadmin must not be able to write the server config');
    }

    public function testAnonymousIsSentToTheAdminLogin(): void
    {
        $this->client->request('GET', '/admin/htaccess-lock');

        self::assertResponseRedirects('/admin/login');
    }

    public function testEnablingWritesTheManagedBlockAndKeepsTheRestOfTheFile(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->loginAsTechSupport();

        $this->save(['ips' => "127.0.0.1\n203.0.113.0/24", 'exempt_paths' => "/health\n/webhooks/*", 'status_code' => '404', 'error_file' => '/blocked.html']);

        self::assertResponseRedirects('/admin/htaccess-lock');
        $file = $this->htaccess();
        self::assertStringStartsWith('# ###> app/htaccess-lock ###', $file);
        self::assertStringContainsString("Require ip 127.0.0.1\n    Require ip 203.0.113.0/24\n    Require env HTACCESS_LOCK_EXEMPT", $file);
        self::assertStringContainsString('SetEnvIf Request_URI "^/health/?$" HTACCESS_LOCK_EXEMPT', $file);
        self::assertStringContainsString('SetEnvIf Request_URI "^/webhooks/" HTACCESS_LOCK_EXEMPT', $file);
        self::assertStringContainsString("ErrorDocument 403 /blocked.html\nErrorDocument 404 /blocked.html", $file);
        self::assertStringEndsWith("\n" . self::ORIGINAL, $file, 'the rest of the file is untouched');

        self::assertSame('1', $this->config('htaccess_lock.enabled'));
        self::assertSame("127.0.0.1\n203.0.113.0/24", $this->config('htaccess_lock.ips'));
        self::assertSame('404', $this->config('htaccess_lock.status_code'));
        self::assertSame('/blocked.html', $this->config('htaccess_lock.error_file'));
    }

    public function testSavingIsAuditedWithoutLoggingTheWhitelistItself(): void
    {
        $this->loginAsTechSupport();

        $this->save(['ips' => '127.0.0.1']);

        $row = $this->em->getConnection()->fetchAssociative("SELECT actor, action, outcome, context FROM audit_log WHERE action = 'admin.htaccess_lock_update'");
        self::assertSame('htl-ts@example.com', $row['actor']);
        self::assertSame('success', $row['outcome']);
        self::assertStringContainsString('enabled=yes ips=1', (string) $row['context']);
        self::assertStringNotContainsString('127.0.0.1', (string) $row['context']);
    }

    public function testASaveThatWouldLockTheAdminOutIsRefusedAndNothingIsWritten(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->loginAsTechSupport();

        $this->save(['ips' => '203.0.113.5']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'lock you out');
        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertNull($this->config('htaccess_lock.enabled'));
        self::assertSame('failure', $this->em->getConnection()->fetchOne("SELECT outcome FROM audit_log WHERE action = 'admin.htaccess_lock_update'"));
    }

    public function testDirectiveInjectionThroughAnyFieldIsRejected(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->loginAsTechSupport();

        $this->save(['ips' => "127.0.0.1\nRequire all granted"]);
        self::assertResponseStatusCodeSame(422);

        $this->save(['exempt_paths' => "/ok\nRequire all granted"]);
        self::assertResponseStatusCodeSame(422);

        $this->save(['error_file' => "/blocked.html\nRequire all granted"]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertStringNotContainsString('Require all granted', $this->htaccess());
    }

    public function testAnErrorFileThatDoesNotExistIsRejected(): void
    {
        $this->loginAsTechSupport();

        $this->save(['error_file' => '/nope.html']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'does not exist');
    }

    public function testTurningTheLockOffRemovesOnlyTheBlockAndKeepsTheSavedWhitelist(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->loginAsTechSupport();
        $this->save(['ips' => "127.0.0.1\n203.0.113.9"]);

        // The real form resubmits the textarea as-is when the radio is flipped to Off.
        $this->save(['enabled' => '0', 'ips' => "127.0.0.1\n203.0.113.9"]);

        self::assertResponseRedirects('/admin/htaccess-lock');
        self::assertSame(self::ORIGINAL, $this->htaccess(), 'original file restored exactly');
        self::assertSame('0', $this->config('htaccess_lock.enabled'));
        $this->client->request('GET', '/admin/htaccess-lock');
        self::assertStringContainsString('203.0.113.9', $this->client->getResponse()->getContent());
    }

    public function testAnInvalidCsrfTokenChangesNothing(): void
    {
        $this->loginAsTechSupport();

        $this->client->request('POST', '/admin/htaccess-lock/save', ['_token' => 'forged', 'enabled' => '1', 'ips' => '127.0.0.1', 'status_code' => '404']);
        self::assertResponseRedirects('/admin/htaccess-lock');
        $this->client->request('POST', '/admin/htaccess-lock/test', ['_token' => 'forged']);
        self::assertResponseRedirects('/admin/htaccess-lock');

        self::assertSame('', $this->htaccess());
        self::assertNull($this->config('htaccess_lock.enabled'));
        self::assertSame([], $this->probe->paths, 'no self-test request without a valid token');
    }

    public function testThePageFlagsAnEnabledLockWhoseBlockIsMissingFromTheFile(): void
    {
        $this->loginAsTechSupport();
        $this->save();
        unlink($this->dir . '/.htaccess');

        $this->client->request('GET', '/admin/htaccess-lock');

        self::assertSelectorTextContains('.warning', 'disagree');
    }

    public function testSelfTestPassIsReportedStoredAndAudited(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->loginAsTechSupport();
        $this->probe->responses = [403, 200, 200, 404];

        $this->client->request('GET', '/admin/htaccess-lock');
        $this->client->submitForm('Run enforcement self-test');
        self::assertResponseRedirects('/admin/htaccess-lock');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.success', 'Self-test passed');
        self::assertSelectorTextContains('h3', 'PASSED');
        self::assertSame(self::ORIGINAL, $this->htaccess(), 'the test leaves the file exactly as it found it');
        self::assertCount(4, $this->probe->paths);
        self::assertSame('success', $this->em->getConnection()->fetchOne("SELECT outcome FROM audit_log WHERE action = 'admin.htaccess_lock_test'"));
        self::assertTrue(self::getContainer()->get(HtaccessLockManager::class)->lastTest()?->passed());
    }

    public function testSelfTestFailureOnAServerThatIgnoresHtaccessIsLoud(): void
    {
        $this->loginAsTechSupport();
        $this->probe->responses = [200, 200, 200, 200];

        $this->client->request('GET', '/admin/htaccess-lock');
        $this->client->submitForm('Run enforcement self-test');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.error', 'FAILED');
        self::assertSelectorTextContains('h3', 'FAILED');
        self::assertStringContainsString('not enforcing .htaccess', $this->client->getResponse()->getContent());
        self::assertSame('failure', $this->em->getConnection()->fetchOne("SELECT outcome FROM audit_log WHERE action = 'admin.htaccess_lock_test'"));
    }

    public function testTheSidebarLinkIsForTechSupportOnly(): void
    {
        $this->createTestAdmin('htl-super@example.com', 'HTL Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('htl-super@example.com');
        self::assertSelectorNotExists('aside.sidebar a[href="/admin/htaccess-lock"]');

        $this->client->request('GET', '/admin/logout');
        $this->cleanupAdmins();
        $this->loginAsTechSupport();
        $this->client->request('GET', '/admin/dashboard');
        self::assertSelectorExists('aside.sidebar a[href="/admin/htaccess-lock"]');
    }

    private function cleanupAdmins(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM admin WHERE email LIKE 'htl-%@example.com'");
        $this->em->clear();
    }
}
