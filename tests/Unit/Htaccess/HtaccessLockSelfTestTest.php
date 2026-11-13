<?php

declare(strict_types=1);

namespace App\Tests\Unit\Htaccess;

use App\Htaccess\HtaccessFile;
use App\Htaccess\HtaccessLockRenderer;
use App\Htaccess\HtaccessLockSelfTest;
use App\Htaccess\HtaccessLockSettings;
use App\Tests\Support\ScriptedHtaccessProbe;
use PHPUnit\Framework\TestCase;

final class HtaccessLockSelfTestTest extends TestCase
{
    private const ORIGINAL = "RewriteEngine On\nRewriteRule ^ index.php [L]\n";

    private string $dir;
    private HtaccessFile $file;
    private ScriptedHtaccessProbe $probe;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htl-selftest-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->file = new HtaccessFile($this->dir . '/.htaccess', $this->dir . '-lock');
        $this->probe = new ScriptedHtaccessProbe();
        $this->probe->onCall = fn (): ?string => $this->file->currentBlock();
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.htaccess');
        @rmdir($this->dir);
        @unlink($this->dir . '-lock');
    }

    private function runSelfTest(?HtaccessFile $file = null, ?HtaccessLockSettings $configured = null): \App\Htaccess\HtaccessLockSelfTestReport
    {
        $selfTest = new HtaccessLockSelfTest($file ?? $this->file, new HtaccessLockRenderer(), $this->probe, 0);

        return $selfTest->run('https', 'example.test', 443, $configured ?? new HtaccessLockSettings(true, ['203.0.113.1'], [], 404, ''));
    }

    public function testPassesOnAServerThatEnforcesAndRestoresTheFileExactly(): void
    {
        // deny, exempt path allowed, own ip allowed, configured block (404)
        $this->probe->responses = [403, 200, 200, 404];

        $report = $this->runSelfTest();

        self::assertTrue($report->passed(), json_encode($report->steps));
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'), 'original restored byte-for-byte');
        self::assertSame(
            [
                HtaccessLockSelfTest::PROBE_PATH,
                HtaccessLockSelfTest::PROBE_EXEMPT_PATH,
                HtaccessLockSelfTest::PROBE_PATH,
                HtaccessLockSelfTest::PROBE_PATH,
            ],
            $this->probe->paths,
        );
    }

    public function testEachProbeRunsAgainstTheRightTemporaryBlock(): void
    {
        $this->probe->responses = [403, 200, 200, 404];

        $this->runSelfTest();

        self::assertStringContainsString('Require ip 192.0.2.1', $this->probe->blocksSeen[0], 'step 1: server IP not whitelisted');
        self::assertStringNotContainsString('127.0.0.1', $this->probe->blocksSeen[0]);
        self::assertStringContainsString('HTACCESS_LOCK_EXEMPT', $this->probe->blocksSeen[1], 'step 2: exempt path');
        self::assertStringContainsString('Require ip 127.0.0.1', $this->probe->blocksSeen[2], 'step 3: server own IP inserted');
        self::assertStringContainsString('RewriteRule ^ - [R=404,L]', $this->probe->blocksSeen[3], 'step 4: the configured 404 response');
    }

    public function testFailsWhenTheServerIgnoresHtaccess(): void
    {
        $this->probe->responses = [200, 200, 200, 200];

        $report = $this->runSelfTest();

        self::assertFalse($report->passed());
        self::assertFalse($report->steps[1]['passed']);
        self::assertStringContainsString('not enforcing', $report->steps[1]['detail']);
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'), 'restored even though the test failed');
    }

    public function testFailsWhenTheServerRejectsTheDirectives(): void
    {
        $this->probe->responses = [500, 500, 500, 500];

        $report = $this->runSelfTest();

        self::assertFalse($report->passed());
        self::assertStringContainsString('rejected the generated directives', $report->steps[1]['detail']);
    }

    public function testFailsWhenTheConfiguredStatusRemapIsNotHonoured(): void
    {
        $this->probe->responses = [403, 200, 200, 403];

        $report = $this->runSelfTest();

        self::assertFalse($report->passed());
        $last = $report->steps[4];
        self::assertStringContainsString('(404)', $last['label']);
        self::assertFalse($last['passed']);
    }

    public function testReportsAnUnreachableSiteAndStillRestores(): void
    {
        $this->probe->responses = [null, null, null, null];

        $report = $this->runSelfTest();

        self::assertFalse($report->passed());
        self::assertStringContainsString('could not reach', $report->steps[1]['detail']);
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
    }

    public function testRestoresWhenTheProbeThrows(): void
    {
        $this->probe->onCall = static function (): never {
            throw new \RuntimeException('boom');
        };

        $report = $this->runSelfTest();

        self::assertFalse($report->passed());
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
    }

    public function testRemovesTheFileAgainIfItDidNotExistBefore(): void
    {
        unlink($this->dir . '/.htaccess');
        $this->probe->responses = [403, 200, 200, 404];

        $report = $this->runSelfTest();

        self::assertTrue($report->passed(), json_encode($report->steps));
        self::assertFileDoesNotExist($this->dir . '/.htaccess');
    }

    public function testStopsImmediatelyWhenTheFileIsNotWritable(): void
    {
        $unwritable = new class ($this->dir . '/.htaccess', $this->dir . '-lock') extends HtaccessFile {
            public function isWritable(): bool
            {
                return false;
            }
        };

        $report = $this->runSelfTest($unwritable);

        self::assertFalse($report->passed());
        self::assertCount(1, $report->steps);
        self::assertSame([], $this->probe->paths, 'no request is made when nothing could be written');
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
    }
}
