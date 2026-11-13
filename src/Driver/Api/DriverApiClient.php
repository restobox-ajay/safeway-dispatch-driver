<?php

declare(strict_types=1);

namespace App\Driver\Api;

use App\Entity\DriverApiSession;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The one way this app talks to the Safeway Dispatch Driver API (docs/driver-api.md, a copy of safeway-dispatch's).
 *
 * Sends Accept, X-App-Version, X-Device-Id, Idempotency-Key on writes, the Host override for a WAMP vhost
 * reached by IP, plus X-Forwarded-For with the driver's IP. Authenticated calls
 * carry the driver's access token; an expired one is renewed once (DriverTokenStore::refresh) and the call
 * retried. Every failure is a DriverApiException worded like the Android app.
 */
final class DriverApiClient
{
    public const APP_VERSION = 'safeway-web-1.0.0';

    /** Renew a little early, so a call rarely meets an expired token. */
    private const REFRESH_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DriverTokenStore $tokens,
        private readonly RequestStack $requests,
        #[Autowire(env: 'DRIVER_API_BASE_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'DRIVER_API_HOST_HEADER')] private readonly string $hostHeader,
    ) {}

    /**
     * Checks the login (phone number or employee number) and PIN with the Dispatch API and, when they match,
     * signs this browser session in.
     *
     * @return array<string, mixed> the driver
     */
    public function login(string $login, string $pin, string $deviceId): array
    {
        $res = $this->send('POST', 'auth/login', ['json' => [self::loginField($login) => trim($login), 'pin' => $pin]], null, $deviceId);
        if (!\is_array($res) || !\is_string($res['access_token'] ?? null) || !\is_string($res['refresh_token'] ?? null)) {
            throw new DriverApiException('Unexpected answer from Dispatch. Please try again.', 502);
        }
        $driver = \is_array($res['driver'] ?? null) ? $res['driver'] : [];
        $this->tokens->start($res['access_token'], $res['refresh_token'], (int) ($res['expires_in'] ?? 900), $driver, $deviceId);

        return $driver;
    }

    /** Revokes the refresh token at the admin API (best effort) and forgets this session's tokens. */
    public function logout(): void
    {
        $row = $this->tokens->current();
        if ($row !== null) {
            try {
                $this->send('POST', 'auth/logout', [], $row);
            } catch (DriverApiException) {
                // Offline or already revoked: signing out here must still work.
            }
        }
        $this->tokens->end();
    }

    /**
     * Safeway's login takes either `phone` or `employee_number` (docs/driver-api.md section 1). A phone number is
     * digits with the usual punctuation (at least 7 digits); anything else ("E-42") is an employee number.
     */
    public static function loginField(string $login): string
    {
        $login = trim($login);

        return preg_match('/^\+?[\d\s().-]+$/', $login) === 1 && preg_match_all('/\d/', $login) >= 7 ? 'phone' : 'employee_number';
    }

    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = []): mixed
    {
        return $this->authed('GET', $path, $query !== [] ? ['query' => $query] : []);
    }

    /**
     * A write. $idempotencyKey is the one the page made when the driver tapped (so a tap replayed from the
     * offline queue is applied once); a fresh one is used when the caller has none.
     *
     * @param array<string, mixed> $json
     */
    public function post(string $path, array $json = [], ?string $idempotencyKey = null): mixed
    {
        return $this->authed('POST', $path, ['json' => (object) $json], $idempotencyKey);
    }

    /** @param array<string, mixed> $json */
    public function put(string $path, array $json = [], ?string $idempotencyKey = null): mixed
    {
        return $this->authed('PUT', $path, ['json' => (object) $json], $idempotencyKey);
    }

    public function delete(string $path, ?string $idempotencyKey = null): mixed
    {
        return $this->authed('DELETE', $path, [], $idempotencyKey);
    }

    /**
     * multipart/form-data upload (proof photos).
     *
     * @param array<string, string> $fields
     */
    public function upload(string $path, array $fields, string $fileField, string $filePath, string $fileName, string $contentType, ?string $idempotencyKey = null): mixed
    {
        // Built afresh for each send: the body is a one-pass stream, so a retry after a token renewal would
        // otherwise go out empty (and Dispatch would answer "file required").
        $options = static function () use ($fields, $fileField, $filePath, $fileName, $contentType): array {
            $parts = $fields;
            $parts[$fileField] = DataPart::fromPath($filePath, $fileName, $contentType);
            $form = new FormDataPart($parts);

            // A string body, not a stream: a streamed body goes out chunked with no Content-Length, which some
            // PHP-FPM / Apache setups read as empty, so Dispatch saw no "file" and answered 422 (GitHub #8).
            return ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToString()];
        };

        return $this->authed('POST', $path, $options, $idempotencyKey);
    }

    /**
     * A proof photo's image (GET /photos/{photoId}) — the API serves a driver only their own photos.
     *
     * @return array{content: string, type: string}
     */
    public function photo(string $photoId): array
    {
        /** @var array{content: string, type: string} */
        return $this->authed('GET', 'photos/' . rawurlencode($photoId), ['headers' => ['Accept' => 'image/*']], null, true);
    }

    /**
     * @param array<string, mixed>|\Closure(): array<string, mixed> $options a Closure when the body can be sent only once
     * @param bool $raw a 2xx answer comes back as {content, type} instead of decoded JSON (images)
     */
    private function authed(string $method, string $path, array|\Closure $options, ?string $idempotencyKey = null, bool $raw = false): mixed
    {
        $row = $this->tokens->current() ?? throw DriverApiException::sessionExpired();
        $key = $method !== 'GET' ? $idempotencyKey ?? self::uuid() : null;
        $build = static function () use ($options, $key): array {
            $o = $options instanceof \Closure ? $options() : $options;
            if ($key !== null) {
                $o['headers'] = ($o['headers'] ?? []) + ['Idempotency-Key' => $key];
            }

            return $o;
        };

        if ($row->getAccessExpiresAt()->getTimestamp() - time() <= self::REFRESH_MARGIN_SECONDS) {
            $row = $this->renew($row);
        }
        try {
            $result = $this->send($method, $path, $build(), $row, null, $raw);
        } catch (DriverApiException $e) {
            if ($e->status !== 401 || $e->errorCode !== 'TOKEN_INVALID') {
                throw $e;
            }
            $row = $this->renew($row);
            $result = $this->send($method, $path, $build(), $row, null, $raw);
        }
        $this->tokens->touch($row);

        return $result;
    }

    private function renew(DriverApiSession $row): DriverApiSession
    {
        try {
            return $this->tokens->refresh($row, $row->getAccessToken(), function (string $refreshToken) use ($row): array {
                $res = $this->send('POST', 'auth/refresh', ['json' => ['refresh_token' => $refreshToken], 'timeout' => 10, 'max_duration' => 15], null, $row->getDeviceId());
                if (!\is_array($res) || !\is_string($res['access_token'] ?? null) || !\is_string($res['refresh_token'] ?? null)) {
                    throw DriverApiException::sessionExpired();
                }

                return $res;
            });
        } catch (DriverApiException $e) {
            if ($e->offline || ($e->status ?? 0) >= 500) {
                throw $e; // Dispatch unreachable or failing: the tokens may still be good.
            }
            // The refresh token was refused (expired, revoked, PIN changed): back to the login page.
            $this->tokens->end();
            throw DriverApiException::sessionExpired();
        }
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options, ?DriverApiSession $auth, ?string $deviceId = null, bool $raw = false): mixed
    {
        if (trim($this->baseUrl) === '') {
            throw new \LogicException('DRIVER_API_BASE_URL is not set: point it at safeway-dispatch\'s /driver-api/v1/ (see docs/DRIVER_APP.md).');
        }
        $headers = [
            'Accept' => 'application/json, application/problem+json',
            'X-App-Version' => self::APP_VERSION,
            'X-Device-Id' => $auth?->getDeviceId() ?? $deviceId ?? '',
        ];
        $ip = $this->requests->getMainRequest()?->getClientIp();
        if ($ip !== null) {
            $headers['X-Forwarded-For'] = $ip;
        }
        if (trim($this->hostHeader) !== '') {
            $headers['Host'] = trim($this->hostHeader);
        }
        if ($auth !== null) {
            $headers['Authorization'] = 'Bearer ' . $auth->getAccessToken();
        }
        $options['headers'] = ($options['headers'] ?? []) + $headers;
        $options['base_uri'] = rtrim($this->baseUrl, '/') . '/';
        $options['timeout'] ??= 20;
        $options['max_duration'] ??= 60;

        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $status = $response->getStatusCode();
            if ($raw && $status >= 200 && $status < 300) {
                return ['content' => $response->getContent(false), 'type' => $response->getHeaders(false)['content-type'][0] ?? 'application/octet-stream'];
            }
            $body = $this->decode($response);
        } catch (TransportExceptionInterface $e) {
            throw DriverApiException::offline($e);
        }

        if ($status >= 200 && $status < 300) {
            return $status === 204 ? null : $body;
        }

        throw DriverApiException::fromResponse($status, \is_array($body) ? $body : null);
    }

    /** A random UUID v4 (the Idempotency-Key). */
    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function decode(ResponseInterface $response): mixed
    {
        $raw = $response->getContent(false);
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
