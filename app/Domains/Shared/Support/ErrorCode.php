<?php

namespace App\Domains\Shared\Support;

enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case Gone = 'GONE';
    case PageExpired = 'PAGE_EXPIRED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function defaultStatus(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::Gone => 410,
            self::PageExpired => 419,
            self::PayloadTooLarge => 413,
            self::ValidationFailed => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::ServiceUnavailable => 503,
        };
    }

    public function message(): string
    {
        return __('errors.'.$this->value);
    }

    /**
     * Anything unmapped falls back by status class, never straight to
     * SERVER_ERROR: a 4xx labelled "something went wrong on our side" tells
     * the client to retry a request that will never succeed.
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            410 => self::Gone,
            413 => self::PayloadTooLarge,
            419 => self::PageExpired,
            422 => self::ValidationFailed,
            429 => self::TooManyRequests,
            503 => self::ServiceUnavailable,
            default => $status >= 400 && $status < 500 ? self::BadRequest : self::ServerError,
        };
    }
}
