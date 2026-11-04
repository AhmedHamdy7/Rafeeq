<?php

use App\Domains\Shared\Support\ErrorCode;

function containerFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

it('gives the nginx worker user its temp folders, so a request body can spill to disk', function () {
    expect(containerFile('docker/nginx.conf'))->toContain('user www-data;');

    expect(containerFile('Dockerfile'))->toMatch('/chown -R www-data:www-data[^\n]*\/var\/lib\/nginx/');
});

it('routes the errors nginx raises itself to the JSON envelope', function (string $directive) {
    expect(containerFile('docker/nginx.conf'))->toContain($directive);
})->with([
    'error_page 413 @rafeeq_payload_too_large;',
    'error_page 500 502 504 @rafeeq_server_error;',
]);

it('answers nginx errors with the same code and message as the application', function (ErrorCode $code, string $locale) {
    $config = containerFile('docker/nginx.conf');

    expect($config)
        ->toContain('"code":"'.$code->value.'"')
        ->toContain('"'.trans('errors.'.$code->value, locale: $locale).'"');
})->with([
    [ErrorCode::PayloadTooLarge, 'ar'],
    [ErrorCode::PayloadTooLarge, 'en'],
    [ErrorCode::ServerError, 'ar'],
    [ErrorCode::ServerError, 'en'],
]);
