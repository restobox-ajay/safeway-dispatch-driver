<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * Maintenance mode (ADR-069): `var/maintenance.json`, written by bin/rbx-install.sh around an update's swap, makes
 * public/index.php answer 503 before the Kernel boots, so it works even while the DB, cache or code is mid-update.
 * Deliberately dependency-free (required directly, not autoloaded) and self-expiring, so a crashed updater can never
 * leave the site down for good. The visitor IP is REMOTE_ADDR: the allowlist works when the web server sees clients directly.
 */
final class MaintenanceGate
{
    public const DEFAULT_MINUTES = 30;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function flagPath(): string
    {
        return $this->projectDir . '/var/maintenance.json';
    }

    /** @param list<string> $allowIps */
    public function enable(string $message, int $minutes = self::DEFAULT_MINUTES, array $allowIps = [], ?int $now = null): void
    {
        $now ??= time();
        if (!is_dir(dirname($this->flagPath()))) {
            mkdir(dirname($this->flagPath()), 0775, true);
        }
        file_put_contents($this->flagPath(), json_encode([
            'message'   => $message,
            'since'     => $now,
            'expires'   => $now + max(1, $minutes) * 60,
            'allow_ips' => array_values($allowIps),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public function disable(): void
    {
        @unlink($this->flagPath());
    }

    /** @return array{message: string, expires: int, allow_ips: list<string>}|null the flag if present, valid and unexpired */
    public function state(?int $now = null): ?array
    {
        $raw = @file_get_contents($this->flagPath());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (int) ($data['expires'] ?? 0) <= ($now ?? time())) {
            return null;
        }

        return [
            'message'   => (string) ($data['message'] ?? ''),
            'expires'   => (int) $data['expires'],
            'allow_ips' => array_map('strval', (array) ($data['allow_ips'] ?? [])),
        ];
    }

    /** @return array{message: string, expires: int, allow_ips: list<string>}|null the state if this visitor must be blocked */
    public function blockingState(?string $clientIp, ?int $now = null): ?array
    {
        $state = $this->state($now);
        if ($state === null || ($clientIp !== null && in_array($clientIp, $state['allow_ips'], true))) {
            return null;
        }

        return $state;
    }

    /**
     * @param array{message: string, expires: int, allow_ips: list<string>} $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function response(array $state, bool $wantsJson): array
    {
        $headers = ['Retry-After' => '30', 'Cache-Control' => 'no-store'];

        if ($wantsJson) {
            return ['status' => 503, 'headers' => $headers + ['Content-Type' => 'application/json'],
                'body' => json_encode(['status' => 'maintenance', 'message' => $state['message']], JSON_THROW_ON_ERROR)];
        }

        $message = htmlspecialchars($state['message'] !== '' ? $state['message'] : 'We are performing scheduled maintenance.', ENT_QUOTES);

        return ['status' => 503, 'headers' => $headers + ['Content-Type' => 'text/html; charset=utf-8'], 'body' => <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <meta http-equiv="refresh" content="10"><title>Maintenance</title>
            <style>body{font:16px system-ui,sans-serif;background:#f6f8fb;color:#1e293b;display:grid;place-items:center;min-height:100vh;margin:0}
            main{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.4rem}small{color:#64748b}</style></head>
            <body><main><h1>Back shortly</h1><p>{$message}</p><p><small>This page refreshes automatically.</small></p></main></body></html>
            HTML];
    }

    /** Called from public/index.php: sends the 503 page and exits when this visitor is blocked. */
    public function run(): void
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $state = $this->blockingState($ip);
        if ($state === null) {
            return;
        }

        $response = $this->response($state, str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json'));
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header("$name: $value");
        }
        echo $response['body'];
        exit;
    }
}
