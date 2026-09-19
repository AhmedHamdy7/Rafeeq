<?php

use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

beforeEach(function () {
    config()->set('app.debug', false);
});

/** Middleware runs outside Route::run()'s own HttpResponseException catch. */
final class ShortCircuitingMiddleware
{
    public function handle($request, Closure $next)
    {
        throw new HttpResponseException(response()->json(['deliberate' => true], 202));
    }
}

it('renders a deliberate response thrown from middleware instead of swallowing it into a 500', function () {
    // Thrown from a route action, Route::run() catches HttpResponseException
    // itself and the handler never sees it. Thrown from MIDDLEWARE it runs
    // outside that try/catch, reaches renderViaCallbacks() ahead of the
    // framework's own unwrapping, and a Throwable catch-all would turn a
    // deliberate response into a generic 500.
    Route::get('/api/test/short-circuit', fn () => response()->json(['unreached' => true]))
        ->middleware(ShortCircuitingMiddleware::class);

    $this->getJson('/api/test/short-circuit')
        ->assertStatus(202)
        ->assertExactJson(['deliberate' => true]);
});

it('preserves Retry-After on a throttled response', function () {
    Route::get('/api/test/throttled', function () {
        throw new TooManyRequestsHttpException(60, 'Slow down');
    });

    $response = $this->getJson('/api/test/throttled');

    $response->assertStatus(429)
        ->assertJsonPath('error.code', ErrorCode::TooManyRequests->value);

    expect($response->headers->get('Retry-After'))->toBe('60');
});

it('preserves Allow on a method-not-allowed response', function () {
    Route::get('/api/test/allow', function () {
        throw new HttpException(405, 'Nope', headers: ['Allow' => 'GET, HEAD']);
    });

    $response = $this->getJson('/api/test/allow');

    $response->assertStatus(405)
        ->assertJsonPath('error.code', ErrorCode::MethodNotAllowed->value);

    expect($response->headers->get('Allow'))->toBe('GET, HEAD');
});

it('never labels a 4xx as a server error', function (int $status, string $expectedCode) {
    Route::get("/api/test/status-{$status}", fn () => abort($status));

    $this->getJson("/api/test/status-{$status}")
        ->assertStatus($status)
        ->assertJsonPath('error.code', $expectedCode);
})->with([
    [400, 'BAD_REQUEST'],
    [403, 'FORBIDDEN'],
    [404, 'NOT_FOUND'],
    [409, 'CONFLICT'],
    [410, 'GONE'],
    [402, 'BAD_REQUEST'], // unmapped 4xx falls back by status class, not to SERVER_ERROR
]);

it('still reports genuine failures as a server error without leaking the message in production', function () {
    app()->detectEnvironment(fn () => 'production');

    Route::get('/api/test/boom', function () {
        throw new RuntimeException('database credentials are hunter2');
    });

    $response = $this->getJson('/api/test/boom');

    $response->assertStatus(500)->assertJsonPath('error.code', ErrorCode::ServerError->value);

    expect($response->json('error.message'))->not->toContain('hunter2');
});
