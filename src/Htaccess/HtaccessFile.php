<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Reads and edits the managed block of a real `.htaccess` file — and nothing else in it.
 *
 * Everything outside the BEGIN/END markers is preserved byte-for-byte (the front-controller rewrite
 * rules, other security headers, …). A new block is PREPENDED so its rewrite/authorisation rules run
 * before any catch-all rules below them. A file with unbalanced markers is refused rather than guessed
 * at: silently mangling a web-server config is worse than an error.
 *
 * Writes are serialised with a lock file and, when the directory allows, made atomically (temp file +
 * rename) so a concurrent request never reads a half-written `.htaccess`.
 */
class HtaccessFile
{
    public function __construct(
        private readonly string $path,
        private readonly string $lockPath,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The web root is, by definition, the directory the .htaccess lives in. */
    public function docroot(): string
    {
        return \dirname($this->path);
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isWritable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_dir($this->docroot()) && is_writable($this->docroot());
    }

    public function read(): string
    {
        if (!$this->exists()) {
            return '';
        }
        $content = file_get_contents($this->path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $this->path));
        }

        return $content;
    }

    public function hasBlock(): bool
    {
        return $this->blockBounds($this->read()) !== null;
    }

    /** The currently installed managed block (markers included), or null. */
    public function currentBlock(): ?string
    {
        $content = $this->read();
        $bounds = $this->blockBounds($content);

        return $bounds === null ? null : substr($content, $bounds[0], $bounds[1] - $bounds[0]);
    }

    /** Install or replace the managed block. @param string $block Rendered block, markers included. */
    public function writeBlock(string $block): void
    {
        $this->locked(function () use ($block): void {
            $content = $this->read();
            $bounds = $this->blockBounds($content);

            $new = $bounds === null
                ? $block . ($content === '' ? '' : "\n" . $content)
                : substr($content, 0, $bounds[0]) . $block . substr($content, $bounds[1]);

            $this->putContents($new);
        });
    }

    /** Remove the managed block (and the blank separator line we added), leaving the rest untouched. */
    public function removeBlock(): void
    {
        $this->locked(function (): void {
            if (!$this->exists()) {
                return;
            }
            $content = $this->read();
            $bounds = $this->blockBounds($content);
            if ($bounds === null) {
                return;
            }

            // writeBlock() prepends "<block>\n<original>", so drop exactly that one separator newline and the
            // original file comes back byte-for-byte.
            $after = substr($content, $bounds[1]);
            if ($bounds[0] === 0 && str_starts_with($after, "\n")) {
                $after = substr($after, 1);
            }
            $this->putContents(substr($content, 0, $bounds[0]) . $after);
        });
    }

    /**
     * Put the file back exactly as captured by {@see snapshot()}. Null means "the file did not exist".
     */
    public function restore(?string $snapshot): void
    {
        $this->locked(function () use ($snapshot): void {
            if ($snapshot === null) {
                if ($this->exists() && !@unlink($this->path)) {
                    throw new \RuntimeException(sprintf('Cannot remove %s.', $this->path));
                }

                return;
            }
            $this->putContents($snapshot);
        });
    }

    public function snapshot(): ?string
    {
        return $this->exists() ? $this->read() : null;
    }

    /**
     * @return array{0:int,1:int}|null Byte offsets [start, end) of the managed block incl. trailing newline
     */
    private function blockBounds(string $content): ?array
    {
        $start = strpos($content, HtaccessLockRenderer::BEGIN);
        $endMarker = strpos($content, HtaccessLockRenderer::END);

        if ($start === false && $endMarker === false) {
            return null;
        }
        if ($start === false || $endMarker === false || $endMarker < $start
            || substr_count($content, HtaccessLockRenderer::BEGIN) !== 1
            || substr_count($content, HtaccessLockRenderer::END) !== 1) {
            throw new \RuntimeException(sprintf(
                '%s has unbalanced Htaccess Lock markers; refusing to edit it. Fix or remove the "%s" / "%s" lines by hand.',
                $this->path,
                HtaccessLockRenderer::BEGIN,
                HtaccessLockRenderer::END,
            ));
        }

        $end = $endMarker + \strlen(HtaccessLockRenderer::END);
        if (($content[$end] ?? '') === "\n") {
            ++$end;
        }

        return [$start, $end];
    }

    private function putContents(string $content): void
    {
        $dir = $this->docroot();
        $mode = $this->exists() ? (fileperms($this->path) & 0777) : 0644;

        if (is_writable($dir)) {
            $tmp = tempnam($dir, '.htl-');
            if ($tmp === false) {
                throw new \RuntimeException(sprintf('Cannot create a temporary file in %s.', $dir));
            }
            try {
                if (file_put_contents($tmp, $content) === false || !chmod($tmp, $mode) || !rename($tmp, $this->path)) {
                    throw new \RuntimeException(sprintf('Cannot write %s.', $this->path));
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            return;
        }

        // Directory not writable but the file itself is (common on locked-down hosts): write in place.
        if (file_put_contents($this->path, $content, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->path));
        }
    }

    private function locked(callable $operation): void
    {
        $dir = \dirname($this->lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create lock directory %s.', $dir));
        }

        $handle = fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file %s.', $this->lockPath));
        }

        try {
            flock($handle, LOCK_EX);
            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
