<?php

declare(strict_types=1);

namespace App\Tests\Support\Driver;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for the fvstexpress-admin driver API in the test environment (framework.http_client
 * mock_response_factory). Tests script answers per "METHOD path" and read back what was sent. State is
 * static because the test kernel (and so this service) is rebuilt between requests.
 */
final class FakeAdminApi
{
    /** @var array<string, list<array{0: int, 1: mixed}|\Closure|'offline'>> "POST stops/P-1/picking-up" => answers, used in turn (the last repeats) */
    private static array $answers = [];

    /** @var list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$answers = [];
        self::$calls = [];
    }

    /** @param array<mixed>|null $body */
    public static function answer(string $method, string $path, int $status = 200, mixed $body = null): void
    {
        self::$answers[$method . ' ' . $path][] = [$status, $body];
    }

    /**
     * Replaces whatever was scripted for this call with one answer.
     *
     * @param array<mixed>|null $body
     */
    public static function only(string $method, string $path, int $status = 200, mixed $body = null): void
    {
        self::$answers[$method . ' ' . $path] = [[$status, $body]];
    }

    /** No connection for this call (the transport fails). */
    public static function offline(string $method, string $path): void
    {
        self::$answers[$method . ' ' . $path][] = 'offline';
    }

    /** Answers a signed-in session needs: login returns tokens for driver #7. */
    public static function signedIn(array $driver = []): void
    {
        self::answer('POST', 'auth/login', 200, [
            'access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'expires_in' => 900,
            'driver' => $driver + ['id' => 7, 'name' => 'Marcus Chen', 'employee_card_number' => 'EMP-00412', 'vehicle_type' => 'Cargo Van', 'online' => false, 'dispatch_phone' => '+16045550100'],
        ]);
        self::answer('GET', 'messages', 200, ['unread' => 0, 'messages' => []]);
    }

    /** @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> */
    public static function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(self::$calls, static fn (array $c): bool => $c['method'] === $method && $c['path'] === $path));
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $parts = parse_url($url);
        $path = (string) preg_replace('#^/driver-api/v1/#', '', $parts['path'] ?? '');
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$k, $v] = explode(': ', $line, 2) + [1 => ''];
            $headers[strtolower($k)] = $v;
        }
        $body = $options['body'] ?? '';
        if (is_iterable($body) && !\is_string($body)) {
            $chunks = '';
            foreach ($body as $chunk) {
                $chunks .= $chunk;
            }
            $body = $chunks;
        }
        self::$calls[] = ['method' => $method, 'path' => $path, 'query' => $parts['query'] ?? '', 'headers' => $headers, 'body' => (string) $body];

        $key = $method . ' ' . $path;
        $queue = self::$answers[$key] ?? null;
        if ($queue === null) {
            return new MockResponse(json_encode(['error' => 'Not scripted: ' . $key]), ['http_code' => 404]);
        }
        $next = \count($queue) > 1 ? array_shift(self::$answers[$key]) : $queue[0];
        if ($next === 'offline') {
            throw new TransportException('Could not resolve host: admin.test');
        }
        [$status, $data] = $next;

        return new MockResponse($data === null ? '' : json_encode($data, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
