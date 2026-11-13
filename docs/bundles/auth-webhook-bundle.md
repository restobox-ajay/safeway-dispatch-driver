# auth-webhook-bundle
Dispatches JSON webhooks to admin-configured URLs when authentication events occur, with retry/backoff, an SSRF guard, and per-attempt delivery tracking.

[← Back to main README](../../README.md)

## Overview

The webhook bundle lets an administrator register HTTP(S) endpoints that receive a JSON
payload whenever certain authentication events fire. URLs are configured from the admin
config UI (`/admin/config`, slug `webhook`) and resolved per event with a global fallback.

The flow is:

1. A security event is raised by Symfony (`LoginSuccessEvent` / `LoginFailureEvent`).
2. `App\EventListener\WebhookListener` resolves the target URL (per-event key, falling
   back to `webhook.global_url`), and if one is set, builds a payload and calls
   `WebhookDispatcherInterface::dispatch()`.
3. The active dispatcher delivers the payload:
   - `HttpWebhookDispatcher` (production) POSTs the JSON over HTTP, guarding against SSRF,
     retrying failed attempts up to `webhook.max_retry_attempts`, and recording each
     attempt as a `WebhookDelivery` row.
   - `InMemoryWebhookDispatcher` (test) records dispatch calls in a static array and
     performs no network I/O.

Which dispatcher is wired to `WebhookDispatcherInterface` depends on the environment
(the HTTP one in dev/prod, the in-memory one in the test environment).

## Configuration

Config page provider: `App\Config\WebhookConfigPage` — slug `webhook`, title "Webhooks".
All keys are stored in the `config` table and read through `ConfigService`. Every field is
rendered as a plain text input; defaults below come from `WebhookConfigPage::getFields()`.

| Key                            | Type   | Default | Meaning |
|--------------------------------|--------|---------|---------|
| `webhook.global_url`           | text   | `''`    | Fallback endpoint used for any event whose per-event URL is empty. If both the per-event key and this are empty, no webhook fires for that event. |
| `webhook.login_url`            | text   | `''`    | Endpoint for login events (`login.success` and `login.failure`). Used in preference to `global_url` when set. |
| `webhook.registration_url`     | text   | `''`    | Fires on successful registration (`registration` event), dispatched inline from `RegistrationController::register`. Falls back to `webhook.global_url`. |
| `webhook.password_reset_url`   | text   | `''`    | Fires on a completed password reset (`password_reset` event), dispatched from `PasswordResetController::reset`. Falls back to `webhook.global_url`. |
| `webhook.lockout_url`          | text   | `''`    | Fires when an account is locked out (`lockout` event), dispatched from `LoginRateLimitListener::maybeApplyLockout`. Falls back to `webhook.global_url`. |
| `webhook.max_retry_attempts`   | text   | `'3'`   | Maximum number of HTTP delivery attempts (read via `getInt`, floored to `max(1, …)`). A 2xx response stops retries early; otherwise the dispatcher tries up to this many times. |

## Triggered events

Login events go through `WebhookListener` (`#[AsEventListener]`); the other three are
dispatched inline at the point the action completes. Every trigger resolves its per-event
URL first and falls back to `webhook.global_url`; if both are empty, nothing fires.

| Trigger | `event_type` | Dispatched from | Config URL key |
|---|---|---|---|
| Successful login | `login.success`  | `WebhookListener::onLoginSuccess` (`LoginSuccessEvent`) | `webhook.login_url` |
| Failed login     | `login.failure`  | `WebhookListener::onLoginFailure` (`LoginFailureEvent`) | `webhook.login_url` |
| Registration     | `registration`   | `RegistrationController::register` | `webhook.registration_url` |
| Password reset   | `password_reset` | `PasswordResetController::reset` | `webhook.password_reset_url` |
| Account lockout  | `lockout`        | `LoginRateLimitListener::maybeApplyLockout` | `webhook.lockout_url` |

## Services & classes

### `App\Service\WebhookDispatcherInterface`

The contract implemented by every dispatcher.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `dispatch` | `dispatch(string $url, array $payload): void` | Deliver `$payload` (an associative array; `event_type` is expected) to `$url`. Implementations decide whether to perform real network I/O, record attempts, and retry. |

### `App\Service\HttpWebhookDispatcher`

Production dispatcher. Implements `WebhookDispatcherInterface`. Constructed with
`EntityManagerInterface $em` and `ConfigService $config`.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `dispatch` | `dispatch(string $url, array $payload): void` | Reads `webhook.max_retry_attempts` (via `getInt`, floored to `max(1, …)`, default 3). JSON-encodes the payload (`JSON_THROW_ON_ERROR`) and derives `event_type` from `payload['event_type']` (default `'unknown'`). Loops up to `maxAttempts`: each iteration calls `sendHttp()`, treats a 200–299 response as success, persists a `WebhookDelivery` row (status `delivered` or `failed`, response code set to the HTTP code or `null` when 0), and flushes. Returns immediately on success; otherwise calls `backoff($attempt)` before the next try (skipped after the final attempt). |
| `sendHttp` | `protected sendHttp(string $url, string $json): int` | Performs the actual POST. First calls `isAllowedUrl($url)` and returns `0` if the URL is rejected (so no request is made). Builds a stream context: `POST`, `Content-Type: application/json`, `Content-Length`, 5s timeout, `ignore_errors` on (so 4xx/5xx bodies are read rather than throwing), and redirects disabled (`follow_location => 0`, `max_redirects => 0`). Calls `@file_get_contents`, then parses the status line from `$http_response_header[0]` and returns the numeric HTTP status, or `0` if none could be parsed. Overridden by the test double to return queued codes. |
| `backoff` | `protected backoff(int $attempt): void` | Delay hook between retries. No-op by default — production or subclasses can introduce a delay (e.g. exponential sleep). The test double overrides it to a no-op so tests run instantly. |
| `isAllowedUrl` | `public static isAllowedUrl(string $url): bool` | SSRF guard. Returns true only for an `http`/`https` URL whose host resolves exclusively to publicly-routable addresses. Returns false when: `parse_url` fails or scheme/host is empty; scheme is not http/https; the host resolves to no IPs; or any resolved IP is private or reserved (checked with `FILTER_VALIDATE_IP` plus `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`). |
| `resolveHost` | `private static resolveHost(string $host): array` | Helper for `isAllowedUrl`. Strips IPv6 brackets; returns the literal as a one-element array if the host is already an IP; otherwise resolves A records via `gethostbynamel` and AAAA records via `dns_get_record`, returning the de-duplicated list of every IP the host maps to. |

### `App\Service\InMemoryWebhookDispatcher`

Test dispatcher. Implements `WebhookDispatcherInterface`. Performs no network I/O and
records every dispatch in a static array.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `dispatch` | `dispatch(string $url, array $payload): void` | Appends `['url' => $url, 'payload' => $payload]` to the static record. Never sends a request. |
| `getDispatched` | `public static getDispatched(): array` | Returns all recorded dispatches (each `['url' => …, 'payload' => …]`). Used by tests to assert which URL/payload were dispatched. |
| `reset` | `public static reset(): void` | Clears the static record. Tests call this in `setUp` to isolate cases. |

### `App\Repository\WebhookDeliveryRepository`

Doctrine repository for `WebhookDelivery`, extending `ServiceEntityRepository<WebhookDelivery>`.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `__construct` | `__construct(ManagerRegistry $registry)` | Binds the repository to the `WebhookDelivery` entity. No custom query methods are defined; the inherited `ServiceEntityRepository` / `EntityRepository` methods (`find`, `findBy`, `findOneBy`, `findAll`, …) are available. |

## Payload format

The dispatcher receives an associative array, JSON-encodes it, and POSTs it as the request
body with `Content-Type: application/json`. The fields built by `WebhookListener` are:

| Field        | Type   | Example                              | Notes |
|--------------|--------|--------------------------------------|-------|
| `event_type` | string | `login.success` / `login.failure`    | Identifies the event; also stored on the `WebhookDelivery` row. |
| `actor`      | string | `user@example.com`                   | On success, `UserInterface::getUserIdentifier()`. On failure, the trimmed `email` request field, or `unknown` if absent/blank. |
| `timestamp`  | string | `2026-06-21T14:03:22+00:00`          | Current time as ISO-8601 / ATOM. |
| `ip`         | string | `203.0.113.7`                        | `Request::getClientIp()`, or `0.0.0.0` if unavailable. |

Example payload (login success):

```json
{
  "event_type": "login.success",
  "actor": "user@example.com",
  "timestamp": "2026-06-21T14:03:22+00:00",
  "ip": "203.0.113.7"
}
```

## Delivery tracking

Every HTTP attempt made by `HttpWebhookDispatcher` is persisted as one
`App\Entity\WebhookDelivery` row (table `webhook_delivery`). A delivery that retries N times
produces N rows.

| Column          | Property       | DB type             | Notes |
|-----------------|----------------|---------------------|-------|
| `id`            | `$id`          | int, identity       | Primary key (`?int`, null before persist). |
| `url`           | `$url`         | varchar(2048)       | Target endpoint. |
| `event_type`    | `$eventType`   | varchar(100)        | Copied from `payload['event_type']` (default `unknown`). |
| `payload`       | `$payload`     | text                | The exact JSON body that was sent. |
| `status`        | `$status`      | varchar(20)         | `delivered` for a 2xx response, otherwise `failed`. |
| `response_code` | `$responseCode`| int, nullable       | HTTP status code, or `null` when the code was 0 (request blocked by the SSRF guard or no status line parsed). |
| `attempted_at`  | `$attemptedAt` | datetime_immutable  | Defaults to construction time. |

Accessors: `getId()`, `getUrl()`, `getEventType()`, `getPayload()`, `getStatus()`,
`setStatus(string)`, `getResponseCode()`, `setResponseCode(?int)`, `getAttemptedAt()`.
The constructor takes `(string $url, string $eventType, string $payload, string $status,
?\DateTimeImmutable $attemptedAt = null)`.

Retry / status behavior (from `dispatch`):

- A response of 200–299 marks the row `delivered` and **stops** further attempts.
- A non-2xx (or 0) response marks the row `failed`; the loop continues to the next attempt
  up to `webhook.max_retry_attempts`, calling `backoff($attempt)` between tries.
- After the maximum number of failed attempts, no further retries occur and the last row
  remains `failed`.

## Security notes

The webhook URL is a free-text, admin-configured value that flows into
`file_get_contents`, which is a classic SSRF/local-file-read vector. This was flagged as
security finding **C2** and is mitigated by `HttpWebhookDispatcher::isAllowedUrl()`, which
`sendHttp()` calls before issuing any request (returning code `0` — recorded as a `failed`
delivery — when the URL is rejected):

- **Scheme allow-list:** only `http` and `https` are permitted. `file://`, `ftp://`,
  `php://`, `gopher://`, schemeless paths, and garbage strings are rejected — closing the
  local-file-read (`file:///etc/passwd`, `php://filter/...`) vector.
- **Host resolution + IP filtering:** the host is resolved to all of its A and AAAA
  records, and every resolved IP must pass `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
  This blocks loopback (`127.0.0.0/8`, `::1`), private ranges (`10/8`, `172.16/12`,
  `192.168/16`, `fc00::/7`), and reserved/link-local ranges including the cloud metadata
  address `169.254.169.254`. A single private/reserved hit rejects the whole URL, which
  defends against DNS rebinding to an internal address.
- **No redirects:** the request is sent with `follow_location => 0` and
  `max_redirects => 0`, so a public endpoint cannot 30x-redirect the request to an internal
  target after the guard has passed.

## Tests

| Test file | Covers |
|-----------|--------|
| `tests/Unit/Service/WebhookSsrfGuardTest.php` | `isAllowedUrl()` directly (security C2): a data-provider of blocked URLs (file/ftp/php/gopher schemes, schemeless, garbage, loopback v4/v6, cloud metadata, and all three private ranges) must return false; public IP literals must return true. |
| `tests/Functional/Security/WebhookTest.php` | End-to-end via `InMemoryWebhookDispatcher`: login fires exactly one webhook to the configured URL; per-event `login_url` is preferred; payload contains `event_type`/`actor`/`timestamp`/`ip` with correct values; registration with only `login_url` set fires nothing. |
| `tests/Functional/Security/WebhookDeliveryTest.php` | `HttpWebhookDispatcher` retry/tracking via the `ControlledHttpWebhookDispatcher` double: `webhook_delivery` has the required columns; failures retry up to `max_retry_attempts` then succeed; exhausted retries leave the row `failed` with no extra attempts; a first-attempt 2xx stops retries and is logged `delivered`, recording the response code each time. |
| `tests/Functional/Admin/WebhookConfigPageTest.php` | Admin config UI: the `webhook` sub-page appears at `/admin/config`; all six fields render; saving the form persists `global_url` and `max_retry_attempts` to the `config` table. |
| `tests/Service/ControlledHttpWebhookDispatcher.php` | Test double (not a test): subclasses `HttpWebhookDispatcher`, overriding `sendHttp()` to return queued response codes and `backoff()` to a no-op so delivery/retry logic can be tested without real HTTP. |
