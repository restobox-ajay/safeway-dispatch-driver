<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Htaccess\HtaccessProbeInterface;

/**
 * A fake "web server" for the Htaccess Lock self-test: returns scripted status codes in order and records
 * what it was asked for plus (via $onCall) what the .htaccess looked like at that moment. PHPUnit never
 * runs Apache, so real enforcement is verified by hand against a real server (see the ADR-059 evidence).
 */
final class ScriptedHtaccessProbe implements HtaccessProbeInterface
{
    /** @var list<int|null> */
    public array $responses = [];

    /** @var list<string> */
    public array $paths = [];

    /** @var list<string|null> */
    public array $blocksSeen = [];

    /** @var (callable():(string|null))|null */
    public $onCall = null;

    public function status(string $scheme, string $host, int $port, string $path): ?int
    {
        $this->paths[] = $path;
        $this->blocksSeen[] = $this->onCall !== null ? ($this->onCall)() : null;

        return array_shift($this->responses);
    }
}
