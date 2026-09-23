<?php

use App\Domains\Identity\Models\User;

/**
 * Note on the test harness: Symfony's `Request::create()` injects a default
 * `Accept-Language: en-us,en;q=0.5`, so a test can never truly send "no
 * header". `fr` stands in for it — a client asking for a language Rafeeq
 * does not speak is the same decision point as one that asks for nothing.
 */
it('renders errors in the language the client asked for', function (string $header, string $expected) {
    $this->withHeader('Accept-Language', $header)
        ->postJson('/api/v1/auth/session/refresh', ['refreshToken' => str_repeat('a', 64)])
        ->assertStatus(401)
        ->assertJsonPath('error.message', __('errors.AUTH_SESSION_INVALID', locale: $expected));
})->with([
    ['ar', 'ar'],
    ['en', 'en'],
    ['en-GB,en;q=0.9', 'en'],
    ['ar-EG', 'ar'],
    // Quality weighting, not document order: Arabic is listed second but
    // asked for more strongly.
    ['en;q=0.5,ar;q=0.9', 'ar'],
    // Rafeeq speaks neither, and nobody is signed in: the product default.
    ['fr', 'ar'],
    // Asks for French first, Arabic second — honour the order, not the first
    // supported language in our own list.
    ['fr,ar;q=0.8', 'ar'],
]);

it('falls back to the stored preference when the client asks for a language we do not speak', function () {
    $signIn = signIn();

    User::sole()->forceFill(['preferred_language' => 'en'])->save();

    $this->withHeader('Accept-Language', 'fr')
        ->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', ['fullName' => '123'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', __('errors.VALIDATION_FAILED', locale: 'en'));
});

it('lets a supported header override the stored preference', function () {
    $signIn = signIn();

    User::sole()->forceFill(['preferred_language' => 'en'])->save();

    $this->withHeader('Accept-Language', 'ar')
        ->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', ['fullName' => '123'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', __('errors.VALIDATION_FAILED', locale: 'ar'));
});

it('defaults to Arabic for an anonymous caller we cannot serve', function () {
    $this->withHeader('Accept-Language', 'fr')
        ->postJson('/api/v1/auth/session/refresh', ['refreshToken' => str_repeat('a', 64)])
        ->assertStatus(401)
        ->assertJsonPath('error.message', __('errors.AUTH_SESSION_INVALID', locale: 'ar'));
});
