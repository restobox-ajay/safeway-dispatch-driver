<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Serialises whole Htaccess Lock changes across requests and processes (issue #44). Every change is a
 * read-modify-write of the complete policy — read the stored settings, derive and validate the new ones, write
 * .htaccess and the config rows — so two unserialised changes each started from the same snapshot and the last
 * writer silently won (a revoked IP came back; an added one was dropped), and their file and config writes could
 * interleave. The gate runs that whole sequence inside {@see synchronized()}.
 *
 * Its own lock file, NOT {@see HtaccessFile}'s: that one guards each single file write and is taken INSIDE this
 * section, and flock() on a second handle to the same file from the same process would wait on itself.
 */
final class HtaccessLockMutex
{
    public function __construct(private readonly string $lockPath)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function synchronized(callable $operation): mixed
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
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Cannot lock %s.', $this->lockPath));
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
