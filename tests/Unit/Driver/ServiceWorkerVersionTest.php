<?php

declare(strict_types=1);

namespace App\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;

/**
 * The driver service worker serves its app files cache-first, so a phone only picks up a changed app.js /
 * style.css when sw.js itself changes. Its VERSION therefore carries a hash of every cached file: change
 * one of them and this test says what VERSION must become.
 */
final class ServiceWorkerVersionTest extends TestCase
{
    private const SW = __DIR__ . '/../../../public/driver-app/sw.js';

    public function testTheVersionCarriesTheHashOfEveryCachedFile(): void
    {
        $sw = (string) file_get_contents(self::SW);
        $this->assertSame(1, preg_match('/^var VERSION = "fvst-driver-([0-9a-f]{12})";/m', $sw, $version), 'VERSION must be "fvst-driver-<12 hex>"');
        $this->assertSame(1, preg_match('/^var STATIC = \[(.*?)\];/ms', $sw, $static));
        preg_match_all('/"([^"]+)"/', $static[1], $paths);
        $this->assertNotEmpty($paths[1]);

        $hash = hash_init('sha256');
        foreach ($paths[1] as $path) {
            $file = __DIR__ . '/../../../public' . $path;
            $this->assertFileExists($file, 'STATIC lists a missing file');
            // Line endings normalised: a Windows checkout (CRLF) and the server (LF) must agree.
            hash_update($hash, $path . "\n" . str_replace("\r\n", "\n", (string) file_get_contents($file)) . "\n");
        }
        $expected = substr(hash_final($hash), 0, 12);

        $this->assertSame($expected, $version[1], sprintf('A cached driver-app file changed: set VERSION to "fvst-driver-%s" in public/driver-app/sw.js', $expected));
    }
}
