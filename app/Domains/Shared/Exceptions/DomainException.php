<?php

namespace App\Domains\Shared\Exceptions;

use App\Domains\Shared\Support\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * The one exception an Action throws when a business rule refuses a request.
 *
 * It carries an `ErrorCode`, not an HTTP status — the Action layer is not
 * supposed to know about HTTP at all. `ApiExceptionHandler` turns it into
 * the standard error envelope with the code's documented status.
 *
 * The message is intentionally left to the error catalogue under `lang`, so
 * the same failure reads correctly in Arabic and English; pass `$message`
 * only for a genuinely case-specific string.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>|null  $fields
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly ?array $fields = null,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->value, previous: $previous);
    }

    /**
     * `$previous` keeps the underlying cause (a provider timeout, say) in
     * the stack trace for the logs while the client still receives only the
     * catalogue message.
     *
     * @param  array<string, array<int, string>>|null  $fields
     * @param  array<string, string>  $headers
     */
    public static function of(
        ErrorCode $code,
        ?string $message = null,
        ?array $fields = null,
        array $headers = [],
        ?Throwable $previous = null,
    ): self {
        return new self($code, $message, $fields, $headers, $previous);
    }

    /**
     * Rate-limit style refusals must tell the client when to come back, or
     * the app has to guess and will hammer the endpoint.
     */
    public static function retryAfter(ErrorCode $code, int $seconds, ?string $message = null): self
    {
        return new self($code, $message, headers: ['Retry-After' => (string) max($seconds, 1)]);
    }
}
