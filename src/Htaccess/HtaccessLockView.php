<?php

declare(strict_types=1);

namespace App\Htaccess;

/** A read-only snapshot for display: the settings, the health of the .htaccess file, and the last self-test. */
final readonly class HtaccessLockView
{
    /** @param array{exists:bool,writable:bool,hasBlock:bool,inSync:bool,error:?string} $fileState */
    public function __construct(
        public HtaccessLockSettings $settings,
        public array $fileState,
        public string $filePath,
        public ?HtaccessLockSelfTestReport $lastTest,
        public ?string $yourIp,
    ) {
    }
}
