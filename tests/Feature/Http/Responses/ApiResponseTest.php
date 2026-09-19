<?php

use App\Domains\Shared\Support\ErrorCode;
use App\Http\Responses\ApiResponse;

it('wraps successful data in the {success, data} envelope', function () {
    $response = ApiResponse::success(['id' => 1]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'success' => true,
            'data' => ['id' => 1],
        ]);
});

it('includes meta only when explicitly given', function () {
    $withoutMeta = ApiResponse::success(['id' => 1]);
    $withMeta = ApiResponse::success(['id' => 1], meta: ['page' => 1]);

    expect($withoutMeta->getData(true))->not->toHaveKey('meta')
        ->and($withMeta->getData(true))->toHaveKey('meta', ['page' => 1]);
});

it('wraps errors in the {success, error: {code, message, fields}} envelope', function () {
    $response = ApiResponse::error(ErrorCode::ValidationFailed, fields: ['seats' => ['المتاح 0 من 3']]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => __('errors.VALIDATION_FAILED'),
                'fields' => ['seats' => ['المتاح 0 من 3']],
            ],
        ]);
});

it('derives the default HTTP status from the error code', function (ErrorCode $code, int $status) {
    expect(ApiResponse::error($code)->getStatusCode())->toBe($status);
})->with([
    [ErrorCode::ValidationFailed, 422],
    [ErrorCode::Unauthenticated, 401],
    [ErrorCode::Forbidden, 403],
    [ErrorCode::NotFound, 404],
    [ErrorCode::TooManyRequests, 429],
    [ErrorCode::ServerError, 500],
]);

it('renders every error message bilingually depending on the app locale', function () {
    app()->setLocale('ar');
    expect(ErrorCode::Forbidden->message())->toBe('لا تملك صلاحية القيام بهذا الإجراء.');

    app()->setLocale('en');
    expect(ErrorCode::Forbidden->message())->toBe('You are not authorized to perform this action.');
});
