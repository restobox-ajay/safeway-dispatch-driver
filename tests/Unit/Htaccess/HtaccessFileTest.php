<?php

declare(strict_types=1);

namespace App\Tests\Unit\Htaccess;

use App\Htaccess\HtaccessFile;
use App\Htaccess\HtaccessLockRenderer;
use PHPUnit\Framework\TestCase;

final class HtaccessFileTest extends TestCase
{
    private string $dir;
    private HtaccessFile $file;

    private const BLOCK_A = HtaccessLockRenderer::BEGIN . "\nRequire ip 1.2.3.4\n" . HtaccessLockRenderer::END . "\n";
    private const BLOCK_B = HtaccessLockRenderer::BEGIN . "\nRequire ip 5.6.7.8\n" . HtaccessLockRenderer::END . "\n";
    private const ORIGINAL = "# front controller\nRewriteEngine On\nRewriteRule ^ index.php [L]\n";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htl-file-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->file = new HtaccessFile($this->dir . '/.htaccess', $this->dir . '-locks/lock');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
        @unlink($this->dir . '-locks/lock');
        @rmdir($this->dir . '-locks');
    }

    public function testCreatesTheFileWhenMissing(): void
    {
        self::assertFalse($this->file->exists());
        self::assertTrue($this->file->isWritable());

        $this->file->writeBlock(self::BLOCK_A);

        self::assertSame(self::BLOCK_A, file_get_contents($this->dir . '/.htaccess'));
    }

    public function testPrependsTheBlockAndPreservesEverythingElseByteForByte(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);

        $this->file->writeBlock(self::BLOCK_A);

        self::assertSame(self::BLOCK_A . "\n" . self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
        self::assertSame(self::BLOCK_A, $this->file->currentBlock());
    }

    public function testRewritingReplacesInPlaceAndIsIdempotent(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->file->writeBlock(self::BLOCK_A);
        $this->file->writeBlock(self::BLOCK_B);
        $afterB = file_get_contents($this->dir . '/.htaccess');
        $this->file->writeBlock(self::BLOCK_B);

        self::assertSame(self::BLOCK_B . "\n" . self::ORIGINAL, $afterB);
        self::assertSame($afterB, file_get_contents($this->dir . '/.htaccess'));
        self::assertSame(1, substr_count($afterB, HtaccessLockRenderer::BEGIN));
    }

    public function testRemovingRestoresTheOriginalExactly(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->file->writeBlock(self::BLOCK_A);

        $this->file->removeBlock();

        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
        self::assertFalse($this->file->hasBlock());
    }

    public function testRemovingWhenThereIsNoBlockOrNoFileIsANoOp(): void
    {
        $this->file->removeBlock();
        self::assertFalse($this->file->exists());

        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $this->file->removeBlock();
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
    }

    public function testRefusesToEditAFileWithUnbalancedMarkers(): void
    {
        $broken = HtaccessLockRenderer::BEGIN . "\nRequire ip 1.2.3.4\n# someone deleted the end marker\n";
        file_put_contents($this->dir . '/.htaccess', $broken);

        try {
            $this->file->writeBlock(self::BLOCK_A);
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unbalanced', $e->getMessage());
        }
        self::assertSame($broken, file_get_contents($this->dir . '/.htaccess'), 'the file must be left untouched');
    }

    public function testSnapshotAndRestoreRoundTripIncludingAbsence(): void
    {
        self::assertNull($this->file->snapshot());
        $this->file->writeBlock(self::BLOCK_A);
        self::assertSame(self::BLOCK_A, $this->file->snapshot());

        $this->file->restore(null);
        self::assertFalse($this->file->exists(), 'restoring "did not exist" deletes the file');

        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        $snap = $this->file->snapshot();
        $this->file->writeBlock(self::BLOCK_A);
        $this->file->restore($snap);
        self::assertSame(self::ORIGINAL, file_get_contents($this->dir . '/.htaccess'));
    }

    public function testPreservesFilePermissionsAndLeavesNoTempFiles(): void
    {
        file_put_contents($this->dir . '/.htaccess', self::ORIGINAL);
        chmod($this->dir . '/.htaccess', 0640);

        $this->file->writeBlock(self::BLOCK_A);
        clearstatcache();

        self::assertSame(0640, fileperms($this->dir . '/.htaccess') & 0777);
        self::assertSame(['.htaccess'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }
}
