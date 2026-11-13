<?php

declare(strict_types=1);

namespace App\Driver\Api;

/**
 * A failed call to the Safeway Dispatch Driver API, worded for the driver.
 *
 * The API answers errors as application/problem+json: {type, title, status, code, detail, errors?: [{field, message}]}
 * (docs/driver-api.md section 9). A 409 on on-my-way also names the stop the driver is already on the way to
 * (`on_my_way_stop_id`), passed to the page so it can offer to take that one back.
 */
final class DriverApiException extends \RuntimeException
{
    public const NO_CONNECTION = 'No connection to Dispatch. Check your signal and try again.';
    public const SESSION_EXPIRED = 'Your session expired. Please log in again.';
    /** Error-body fields the page may use, beyond detail / errors / code. */
    private const EXTRA = ['on_my_way_stop_id'];

    /**
     * @param array<string, string> $fieldErrors field (dotted path) => message
     * @param array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly array $fieldErrors = [],
        public readonly bool $offline = false,
        ?\Throwable $previous = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function offline(?\Throwable $previous = null): self
    {
        return new self(self::NO_CONNECTION, offline: true, previous: $previous);
    }

    public static function sessionExpired(): self
    {
        return new self(self::SESSION_EXPIRED, 401, 'SESSION_EXPIRED');
    }

    /**
     * From an error response: the API's `detail`, else its first field error, else a message for the status code.
     *
     * @param array<mixed>|null $body
     */
    public static function fromResponse(int $status, ?array $body): self
    {
        $fields = [];
        if (\is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $key => $error) {
                if (\is_array($error) && \is_string($error['field'] ?? null) && \is_scalar($error['message'] ?? null)) {
                    $fields[$error['field']] = (string) $error['message'];
                } elseif (\is_scalar($error)) {
                    $fields[(string) $key] = (string) $error;
                }
            }
        }
        $code = \is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = match (true) {
            $code === 'INVALID_CREDENTIALS' => 'Wrong phone / employee number or PIN.',
            $code === 'RATE_LIMITED' => 'Too many tries. Wait a few minutes and try again.',
            \is_string($body['detail'] ?? null) && $body['detail'] !== '' => $body['detail'],
            $fields !== [] => (string) reset($fields),
            \is_string($body['title'] ?? null) && $body['title'] !== '' => $body['title'],
            default => match ($status) {
                401 => self::SESSION_EXPIRED,
                403 => 'You are not allowed to do this.',
                404 => 'Not found. It may have been taken off your route.',
                409 => 'This stop changed. Refresh and try again.',
                default => sprintf('Server error (%d).', $status),
            },
        };
        $extra = array_intersect_key(\is_array($body) ? $body : [], array_flip(self::EXTRA));

        return new self($message, $status, $code, $fields, extra: $extra);
    }

    /** The driver must log in again: the access token could not be renewed. */
    public function isSessionExpired(): bool
    {
        return $this->status === 401 && \in_array($this->errorCode, ['TOKEN_INVALID', 'SESSION_EXPIRED'], true);
    }
}
