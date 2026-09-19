<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Http\Request;

it('sets the locale from Accept-Language', function (string $header, string $expected) {
    $middleware = new SetLocaleFromHeader;
    $request = Request::create('/v1/anything', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

    $middleware->handle($request, function () use ($expected) {
        expect(app()->getLocale())->toBe($expected);

        return response('ok');
    });
})->with([
    'Arabic requested' => ['ar', 'ar'],
    'English requested' => ['en', 'en'],
    'Arabic with quality weighting' => ['en;q=0.5,ar;q=0.9', 'ar'],
]);

it('defaults to Arabic when no supported locale is requested', function () {
    $middleware = new SetLocaleFromHeader;
    $request = Request::create('/v1/anything', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr,de']);

    $middleware->handle($request, function () {
        expect(app()->getLocale())->toBe('ar');

        return response('ok');
    });
});
