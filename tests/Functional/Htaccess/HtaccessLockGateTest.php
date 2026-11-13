<?php

declare(strict_types=1);

namespace App\Tests\Functional\Htaccess;

use App\Htaccess\HtaccessLockActor;
use App\Htaccess\HtaccessLockChangedEvent;
use App\Htaccess\HtaccessLockForbiddenException;
use App\Htaccess\HtaccessLockGate;
use App\Htaccess\HtaccessLockManager;
use App\Htaccess\HtaccessLockOutcome;
use App\Htaccess\HtaccessLockRenderer;
use App\Htaccess\HtaccessLockSettings;
use App\Service\ConfigService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The one gate (ADR-062), driven directly. Whatever the adapter — admin page, API, console — these are the
 * guarantees it inherits: authorization, validation, the lockout guard, ONE audit row and ONE change event per
 * change, and identical results whichever door (a single add, or the whole-form replace) the same change uses.
 */
final class HtaccessLockGateTest extends KernelTestCase
{
    private const ORIGINAL = "RewriteEngine On\nRewriteRule ^ index.php [L]\n";

    private HtaccessLockGate $gate;
    private HtaccessLockManager $manager;
    private Connection $conn;
    private string $dir;

    /** @var list<HtaccessLockChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->gate = $container->get(HtaccessLockGate::class);
        $this->manager = $container->get(HtaccessLockManager::class);
        $this->conn = $container->get(Connection::class);
        $this->dir = $container->getParameter('kernel.project_dir') . '/var/test-htaccess';

        $this->wipe();
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);

        $this->events = [];
        $container->get(EventDispatcherInterface::class)->addListener(HtaccessLockChangedEvent::class, function (HtaccessLockChangedEvent $e): void {
            $this->events[] = $e;
        });
    }

    protected function tearDown(): void
    {
        $this->wipe();
        parent::tearDown();
    }

    private function wipe(): void
    {
        $this->conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess.lock');
        @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess-gate.lock');
    }

    private function ts(string $ip = '127.0.0.1'): HtaccessLockActor
    {
        return HtaccessLockActor::admin('gate-ts@example.com', $ip, true);
    }

    private function htaccess(): string
    {
        return (string) file_get_contents($this->dir . '/.htaccess');
    }

    /** @return list<array{action:string,outcome:string,actor:string,context:?string,ip:string}> */
    private function audit(): array
    {
        return $this->conn->fetchAllAssociative("SELECT action, outcome, actor, context, ip FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%' ORDER BY id");
    }

    // Issue #44: a change is a read-modify-write of the whole policy. Unserialised, two concurrent changes each
    // started from the same snapshot and the last writer won — a revoked IP silently came back, or an added one
    // was dropped. Here a second process plays the other request: it takes the gate's lock, reads the policy,
    // and only writes its (by then stale) result a moment later. Our change must wait for it and build on it.
    public function testAConcurrentChangeIsNotLost(): void
    {
        $this->conn->insert('config', ['config_key' => HtaccessLockManager::KEY_IPS, 'config_value' => '198.51.100.1']);

        $other = <<<'PHP'
            [$lockPath, $dbPath, $key, $ip] = array_slice($argv, 1);
            $lock = fopen($lockPath, 'c');
            flock($lock, LOCK_EX);
            $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA busy_timeout = 5000');
            $stmt = $db->prepare('SELECT config_value FROM config WHERE config_key = ?');
            $stmt->execute([$key]);
            $snapshot = (string) $stmt->fetchColumn();
            $stmt->closeCursor(); // end the read, as a real request does (an open cursor pins an old WAL snapshot)
            fwrite(STDOUT, "locked\n"); // straight to the stream: echo is buffered when stdout is a pipe
            fflush(STDOUT);
            usleep(700000);
            $db->prepare('UPDATE config SET config_value = ? WHERE config_key = ?')->execute([$snapshot . "\n" . $ip, $key]);
            flock($lock, LOCK_UN);
            PHP;
        $process = proc_open(
            ['php', '-r', $other, self::getContainer()->getParameter('app.htaccess_lock.gate_lock_path'), (string) $this->conn->getParams()['path'], HtaccessLockManager::KEY_IPS, '198.51.100.7'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        // (Do not read the child's stderr here: that blocks until it exits, i.e. until it has released the lock.)
        self::assertSame("locked\n", fgets($pipes[1]), 'the other request must hold the lock before ours starts');

        $result = $this->gate->addIp($this->ts(), '203.0.113.5');

        self::assertSame(0, proc_close($process));
        self::assertSame(HtaccessLockOutcome::Applied, $result->outcome);
        $stored = explode("\n", (string) $this->conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [HtaccessLockManager::KEY_IPS]));
        sort($stored);
        self::assertSame(['198.51.100.1', '198.51.100.7', '203.0.113.5'], $stored, 'both changes survive');
        self::assertSame($stored, (function (array $ips): array { sort($ips); return $ips; })($result->settings->ips), 'and our answer reflects the policy actually stored');
    }

    // Issue #44 (write side): a process that had already read a policy row must still WRITE its change when the
    // stored value moved under it. Doctrine only issues an UPDATE when the new value differs from the value it LOADED,
    // so "disable" after a concurrent enable wrote nothing: the file lost its block, the config still said enabled.
    public function testAChangeIsStoredEvenWhenThisProcessHadReadAnOlderValue(): void
    {
        $this->conn->insert('config', ['config_key' => HtaccessLockManager::KEY_ENABLED, 'config_value' => '0']);
        // This process has read the row as an entity (as any earlier ConfigService read in the same request or
        // worker would) — that loaded value is what Doctrine compares a later write against.
        $config = self::getContainer()->get(ConfigService::class);
        self::assertFalse($config->getBool(HtaccessLockManager::KEY_ENABLED));

        // Meanwhile another request enables the lock (committed outside this process's EntityManager).
        $this->conn->executeStatement('UPDATE config SET config_value = ? WHERE config_key = ?', ['1', HtaccessLockManager::KEY_ENABLED]);

        $result = $this->gate->disable(HtaccessLockActor::console());

        self::assertSame(HtaccessLockOutcome::Applied, $result->outcome);
        self::assertSame('0', $this->conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [HtaccessLockManager::KEY_ENABLED]), 'the stored policy must say what the file says');
        self::assertFalse($this->manager->settings()->enabled);
        self::assertTrue($this->manager->fileState()['inSync']);
    }

    // Issue #44: after a change, a row this process already held as an entity must read back what was stored, not
    // the value it had loaded before another change moved it.
    public function testRowsAlreadyLoadedInThisProcessReadBackWhatWasStored(): void
    {
        $this->conn->insert('config', ['config_key' => HtaccessLockManager::KEY_IPS, 'config_value' => '198.51.100.1']);
        $config = self::getContainer()->get(ConfigService::class);
        self::assertSame('198.51.100.1', $config->getString(HtaccessLockManager::KEY_IPS));

        $this->conn->executeStatement('UPDATE config SET config_value = ? WHERE config_key = ?', ["198.51.100.1\n198.51.100.7", HtaccessLockManager::KEY_IPS]);
        $this->gate->addIp($this->ts(), '203.0.113.5');

        self::assertSame("198.51.100.1\n198.51.100.7\n203.0.113.5", $config->getString(HtaccessLockManager::KEY_IPS));
    }

    public function testOnlyTechSupportMayReadOrChangeAnything(): void
    {
        $plainAdmin = HtaccessLockActor::admin('gate-admin@example.com', '127.0.0.1', false);
        $calls = [
            'view' => fn () => $this->gate->view($plainAdmin),
            'listIps' => fn () => $this->gate->listIps($plainAdmin),
            'listExemptPaths' => fn () => $this->gate->listExemptPaths($plainAdmin),
            'lastTest' => fn () => $this->gate->lastTest($plainAdmin),
            'update' => fn () => $this->gate->update($plainAdmin, ['status_code' => '403']),
            'enable' => fn () => $this->gate->enable($plainAdmin),
            'disable' => fn () => $this->gate->disable($plainAdmin),
            'addIp' => fn () => $this->gate->addIp($plainAdmin, '127.0.0.1'),
            'removeIp' => fn () => $this->gate->removeIp($plainAdmin, '127.0.0.1'),
            'addExemptPath' => fn () => $this->gate->addExemptPath($plainAdmin, '/health'),
            'removeExemptPath' => fn () => $this->gate->removeExemptPath($plainAdmin, '/health'),
            'runSelfTest' => fn () => $this->gate->runSelfTest($plainAdmin, 'http', 'localhost', 80),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("$name must refuse a caller who is not tech support.");
            } catch (HtaccessLockForbiddenException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertSame([], $this->audit());
        self::assertSame([], $this->events);
        self::assertFalse($this->conn->fetchOne("SELECT 1 FROM config WHERE config_key LIKE 'htaccess_lock.%'"), 'nothing stored');
    }

    public function testAnAddedIpIsStoredCanonicallyWrittenToTheFileAuditedOnceAndAnnouncedOnce(): void
    {
        $result = $this->gate->addIp($this->ts(), '2001:DB8::1');

        self::assertSame(HtaccessLockOutcome::Applied, $result->outcome);
        self::assertSame(['2001:db8::1'], $result->settings->ips);
        self::assertSame(['2001:db8::1'], $this->manager->settings()->ips);

        $audit = $this->audit();
        self::assertCount(1, $audit, 'one change, one audit row — not one per layer');
        self::assertSame('admin.htaccess_lock_ip_add', $audit[0]['action']);
        self::assertSame('success', $audit[0]['outcome']);
        self::assertSame('gate-ts@example.com', $audit[0]['actor']);
        self::assertSame('127.0.0.1', $audit[0]['ip']);
        self::assertStringContainsString('ip=2001:db8::1', (string) $audit[0]['context']);

        self::assertCount(1, $this->events);
        self::assertSame('admin.htaccess_lock_ip_add', $this->events[0]->action);
        self::assertSame([], $this->events[0]->before->ips);
        self::assertSame(['2001:db8::1'], $this->events[0]->after->ips);
        self::assertSame('gate-ts@example.com', $this->events[0]->actor->email);

        self::assertSame(HtaccessLockOutcome::Conflict, $this->gate->addIp($this->ts(), '2001:0db8:0:0:0:0:0:1')->outcome, 'the same address in another spelling is a duplicate');
    }

    public function testAddingAndReplacingTheWholeListDoTheSameThing(): void
    {
        // The form's "replace everything" door and the API's "add one" door must leave identical state.
        $this->gate->update($this->ts(), ['ips' => "127.0.0.1\n203.0.113.0/24", 'exempt_paths' => '/health', 'status_code' => '403']);
        $this->gate->enable($this->ts());
        $viaReplace = [$this->htaccess(), $this->manager->settings()];

        $this->gate->disable($this->ts());
        $this->conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
        $this->gate->update($this->ts(), ['status_code' => '403']);
        $this->gate->addIp($this->ts(), '127.0.0.1');
        $this->gate->addIp($this->ts(), '203.0.113.0/24');
        $this->gate->addExemptPath($this->ts(), '/health');
        $this->gate->enable($this->ts());
        $viaSingles = [$this->htaccess(), $this->manager->settings()];

        self::assertEquals($viaReplace, $viaSingles);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileEntries')]
    public function testHostileOrMalformedEntriesNeverReachTheFile(string $hostile): void
    {
        $ip = $this->gate->addIp($this->ts(), $hostile);
        $path = $this->gate->addExemptPath($this->ts(), $hostile);

        self::assertSame(HtaccessLockOutcome::Rejected, $ip->outcome);
        self::assertSame(HtaccessLockOutcome::Rejected, $path->outcome);
        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertSame([], $this->events);
        self::assertSame(['failure', 'failure'], array_column($this->audit(), 'outcome'), 'refusals are audited too');
    }

    /** @return iterable<string,array{string}> */
    public static function hostileEntries(): iterable
    {
        yield 'directive injection' => ["1.2.3.4\nRequire all granted"];
        yield 'whole internet' => ['0.0.0.0/0'];
        yield 'garbage' => ['banana'];
        yield 'empty' => [''];
        yield 'CRLF' => ["/ok\r\nRewriteRule .* http://evil [R]"];
    }

    public function testEnablingIsRefusedWhenItWouldLockTheCallerOutAndWritesNothing(): void
    {
        $this->gate->addIp($this->ts('203.0.113.9'), '198.51.100.0/24');

        $result = $this->gate->enable($this->ts('203.0.113.9'));

        self::assertSame(HtaccessLockOutcome::Rejected, $result->outcome);
        self::assertStringContainsString('lock you out', $result->errors[0]);
        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertFalse($this->manager->settings()->enabled);
        self::assertCount(1, $this->events, 'only the successful add was announced');
    }

    public function testRemovingTheEntryThatCoversTheCallerIsRefusedWhileTheLockIsOn(): void
    {
        $this->gate->update($this->ts('203.0.113.9'), ['ips' => "203.0.113.0/24\n198.51.100.1"]);
        self::assertTrue($this->gate->enable($this->ts('203.0.113.9'))->isApplied());
        $before = $this->htaccess();

        $refused = $this->gate->removeIp($this->ts('203.0.113.9'), '203.0.113.0/24');
        self::assertSame(HtaccessLockOutcome::Rejected, $refused->outcome);
        self::assertSame($before, $this->htaccess());

        self::assertTrue($this->gate->removeIp($this->ts('203.0.113.9'), '198.51.100.1')->isApplied(), 'an entry that does not cover the caller can go');
        self::assertNotSame($before, $this->htaccess());
        self::assertSame(HtaccessLockOutcome::NotFound, $this->gate->removeIp($this->ts('203.0.113.9'), '198.51.100.1')->outcome);
    }

    public function testDisableNeverValidatesSoItStillWorksWhenTheStoredPolicyWouldNoLongerPass(): void
    {
        $this->gate->update($this->ts(), ['ips' => '127.0.0.1', 'error_file' => '']);
        $this->gate->enable($this->ts());
        self::assertStringContainsString(HtaccessLockRenderer::BEGIN, $this->htaccess());
        // Policy rot: the error page the stored policy points at has been deleted since.
        $this->conn->executeStatement("INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('htaccess_lock.error_file', '/gone.html')");

        $result = $this->gate->disable(HtaccessLockActor::console());

        self::assertTrue($result->isApplied());
        self::assertSame(self::ORIGINAL, $this->htaccess());
        self::assertFalse($this->manager->settings()->enabled);
        self::assertSame(['127.0.0.1'], $this->manager->settings()->ips, 'the saved whitelist is kept');
    }

    public function testTheConsoleIsAnActorToo(): void
    {
        $this->gate->update($this->ts(), ['ips' => '127.0.0.1']);
        $this->gate->enable($this->ts());
        $this->events = [];
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.htaccess_lock_%'");

        $this->gate->disable(HtaccessLockActor::console());

        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame(['admin.htaccess_lock_disable', 'success', 'console', 'cli'], [$audit[0]['action'], $audit[0]['outcome'], $audit[0]['actor'], $audit[0]['ip']]);
        self::assertCount(1, $this->events);
        self::assertTrue($this->events[0]->actor->console);
    }

    public function testAWriteFailureIsReportedAuditedAndLeavesNoTraceInTheConfig(): void
    {
        file_put_contents($this->dir . '/.htaccess', HtaccessLockRenderer::BEGIN . "\n");   // unbalanced markers: the file refuses edits

        $result = $this->gate->addIp($this->ts(), '203.0.113.1');

        self::assertSame(HtaccessLockOutcome::WriteFailed, $result->outcome);
        self::assertStringContainsString('unbalanced', $result->errors[0]);
        self::assertSame([], $this->manager->settings()->ips);
        self::assertSame([], $this->events);
        self::assertSame(['failure'], array_column($this->audit(), 'outcome'));
        self::assertSame('write failed', $this->audit()[0]['context']);
    }

    public function testUpdateKeepsEverythingItWasNotToldToChange(): void
    {
        $this->gate->update($this->ts(), ['ips' => '127.0.0.1', 'exempt_paths' => '/health', 'status_code' => '403']);

        $this->gate->update($this->ts(), ['status_code' => '404']);

        $settings = $this->manager->settings();
        self::assertSame(404, $settings->statusCode);
        self::assertSame(['127.0.0.1'], $settings->ips);
        self::assertSame(['/health'], $settings->exemptPaths);
        self::assertFalse($settings->enabled);
        self::assertEquals(new HtaccessLockSettings(false, ['127.0.0.1'], ['/health'], 404, ''), $settings);
    }

    public function testEveryAppliedChangeFiresExactlyOneEventAndOneAuditRow(): void
    {
        $actor = $this->ts();
        $this->gate->addIp($actor, '127.0.0.1');
        $this->gate->addExemptPath($actor, '/health');
        $this->gate->update($actor, ['status_code' => '403']);
        $this->gate->enable($actor);
        $this->gate->removeExemptPath($actor, '/health');
        $this->gate->disable($actor);

        self::assertSame(
            ['admin.htaccess_lock_ip_add', 'admin.htaccess_lock_exempt_add', 'admin.htaccess_lock_update', 'admin.htaccess_lock_enable', 'admin.htaccess_lock_exempt_remove', 'admin.htaccess_lock_disable'],
            array_column($this->audit(), 'action'),
        );
        self::assertSame(array_column($this->audit(), 'action'), array_map(static fn (HtaccessLockChangedEvent $e): string => $e->action, $this->events));
    }
}
