<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Proves, against the real web server, that what this feature writes into `.htaccess` is actually
 * enforced — the question a config-file lock can never answer by inspection (AllowOverride off, a
 * proxy in front, or a server that ignores a directive all fail OPEN, silently).
 *
 * Each step swaps in a temporary block, requests the site over loopback (source address 127.0.0.1, see
 * {@see CurlLoopbackProbe}) and checks the status:
 *   1. server IP NOT whitelisted  -> must be blocked (403)
 *   2. an exempt path             -> must NOT be blocked
 *   3. server IP 127.0.0.1 inserted -> must NOT be blocked
 *   4. the configured block response (status + file) -> must use the configured status
 * then puts the original file back byte-for-byte, always, even on failure. Real visitors are blocked
 * for a few seconds while the temporary blocks are live.
 */
final class HtaccessLockSelfTest
{
    public const PROBE_PATH = '/__htaccess_lock_probe__';
    public const PROBE_EXEMPT_PATH = '/__htaccess_lock_probe_exempt__';

    /** TEST-NET-1 (RFC 5737): reserved, can never be this server's own address. */
    private const NOT_THE_SERVER = '192.0.2.1';

    /**
     * @param int $settleMicroseconds pause after every write so the web server re-reads the file (and a
     *                                rewrite inside the same second still gets a new mtime)
     */
    public function __construct(
        private readonly HtaccessFile $file,
        private readonly HtaccessLockRenderer $renderer,
        private readonly HtaccessProbeInterface $probe,
        private readonly int $settleMicroseconds = 1_100_000,
    ) {
    }

    public function run(string $scheme, string $host, int $port, HtaccessLockSettings $configured): HtaccessLockSelfTestReport
    {
        if (!$this->file->isWritable()) {
            return $this->report([$this->step(
                'The application can write the .htaccess file',
                false,
                sprintf('%s is not writable by the web server user.', $this->file->path()),
            )]);
        }

        $steps = [$this->step('The application can write the .htaccess file', true, $this->file->path())];
        $snapshot = $this->file->snapshot();

        try {
            $this->apply(new HtaccessLockSettings(true, [self::NOT_THE_SERVER], [self::PROBE_EXEMPT_PATH], 403, ''));
            $steps[] = $this->expectBlocked('Blocked when the server\'s own IP is not whitelisted', $scheme, $host, $port, self::PROBE_PATH, 403);
            $steps[] = $this->expectAllowed('An exempt path stays reachable from a non-whitelisted IP', $scheme, $host, $port, self::PROBE_EXEMPT_PATH);

            $this->apply(new HtaccessLockSettings(true, ['127.0.0.1', '::1'], [], 403, ''));
            $steps[] = $this->expectAllowed('Allowed once the server\'s own IP (127.0.0.1) is inserted', $scheme, $host, $port, self::PROBE_PATH);

            $this->apply(new HtaccessLockSettings(true, [self::NOT_THE_SERVER], [], $configured->statusCode, $configured->errorFile));
            $steps[] = $this->expectBlocked(
                sprintf('The blocked response uses your configured status (%d)', $configured->statusCode),
                $scheme,
                $host,
                $port,
                self::PROBE_PATH,
                $configured->statusCode,
            );
        } catch (\Throwable $e) {
            $steps[] = $this->step('Self-test ran to completion', false, $e->getMessage());
        } finally {
            $steps[] = $this->restore($snapshot);
        }

        return $this->report($steps);
    }

    private function apply(HtaccessLockSettings $settings): void
    {
        $this->file->writeBlock($this->renderer->render($settings));
        $this->settle();
    }

    /** @return array{label:string,passed:bool,detail:string} */
    private function restore(?string $snapshot): array
    {
        try {
            $this->file->restore($snapshot);
            $this->settle();
            $restored = $this->file->snapshot() === $snapshot;

            return $this->step(
                'The original .htaccess was restored',
                $restored,
                $restored ? 'Byte-for-byte identical to before the test.' : 'The file differs from its pre-test contents — check it by hand.',
            );
        } catch (\Throwable $e) {
            return $this->step('The original .htaccess was restored', false, $e->getMessage() . ' — run `php bin/console app:htaccess-lock:disable` and check the file by hand.');
        }
    }

    /** @return array{label:string,passed:bool,detail:string} */
    private function expectBlocked(string $label, string $scheme, string $host, int $port, string $path, int $expected): array
    {
        $code = $this->probe->status($scheme, $host, $port, $path);

        return match (true) {
            $code === $expected => $this->step($label, true, sprintf('GET %s -> %d', $path, $code)),
            $code === null => $this->step($label, false, 'No HTTP response — could not reach this site over loopback (127.0.0.1). Is the web server listening there?'),
            $code >= 500 => $this->step($label, false, sprintf('GET %s -> %d: the web server rejected the generated directives.', $path, $code)),
            $code < 400 => $this->step($label, false, sprintf('GET %s -> %d, expected %d: the request was NOT blocked — this server is not enforcing .htaccess.', $path, $code, $expected)),
            default => $this->step($label, false, sprintf('GET %s -> %d, expected %d (the request was answered by the application, or the status remap is not supported by this server).', $path, $code, $expected)),
        };
    }

    /** @return array{label:string,passed:bool,detail:string} */
    private function expectAllowed(string $label, string $scheme, string $host, int $port, string $path): array
    {
        $code = $this->probe->status($scheme, $host, $port, $path);

        return match (true) {
            $code === null => $this->step($label, false, 'No HTTP response — could not reach this site over loopback (127.0.0.1).'),
            $code === 403 => $this->step($label, false, sprintf('GET %s -> 403: still blocked.', $path)),
            $code >= 500 => $this->step($label, false, sprintf('GET %s -> %d: the web server rejected the generated directives.', $path, $code)),
            default => $this->step($label, true, sprintf('GET %s -> %d (not blocked)', $path, $code)),
        };
    }

    private function settle(): void
    {
        if ($this->settleMicroseconds > 0) {
            usleep($this->settleMicroseconds);
        }
    }

    /** @return array{label:string,passed:bool,detail:string} */
    private function step(string $label, bool $passed, string $detail): array
    {
        return ['label' => $label, 'passed' => $passed, 'detail' => $detail];
    }

    /** @param list<array{label:string,passed:bool,detail:string}> $steps */
    private function report(array $steps): HtaccessLockSelfTestReport
    {
        return new HtaccessLockSelfTestReport($steps, time());
    }
}
