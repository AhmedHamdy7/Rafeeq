<?php

use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserConsent;

/**
 * Consent versioning (Phase 2 scope, Chapter 2 §27).
 */
it('records consent for every required document at registration', function () {
    signIn();

    $consents = UserConsent::all();

    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('document_type')->map->value->sort()->values()->all())->toBe(['privacy', 'terms'])
        ->and($consents->pluck('source')->unique()->all())->toBe([ConsentSource::Registration])
        ->and($consents->every(fn ($c) => $c->accepted_at !== null))->toBeTrue();
});

it('reports nothing outstanding right after registration', function () {
    $signIn = signIn();

    $this->withToken($signIn['session']['accessToken'])
        ->getJson('/api/v1/account/consents')
        ->assertOk()
        ->assertJsonPath('data.outstanding', [])
        ->assertJsonPath('data.currentVersions.terms', '1.0');
});

it('marks consent outstanding once a new document version is published', function () {
    $signIn = signIn();

    config()->set('rafeeq.legal.terms_version', '2.0');

    $this->withToken($signIn['session']['accessToken'])
        ->getJson('/api/v1/account/consents')
        ->assertOk()
        ->assertJsonPath('data.outstanding', ['terms']);
});

it('keeps the old acceptance on file when a new version is accepted', function () {
    $signIn = signIn();

    config()->set('rafeeq.legal.terms_version', '2.0');

    $this->withToken($signIn['session']['accessToken'])
        ->postJson('/api/v1/account/consents')
        ->assertOk()
        ->assertJsonPath('data.outstanding', []);

    $terms = UserConsent::where('document_type', 'terms')->orderBy('accepted_at')->get();

    // Legal evidence is appended, never overwritten: both versions stand.
    expect($terms)->toHaveCount(2)
        ->and($terms->pluck('document_version')->all())->toBe(['1.0', '2.0'])
        ->and($terms->last()->source)->toBe(ConsentSource::ForcedReaccept);
});

it('does not duplicate a consent that is already on file for the current version', function () {
    $signIn = signIn();

    $this->withToken($signIn['session']['accessToken'])->postJson('/api/v1/account/consents')->assertOk();
    $this->withToken($signIn['session']['accessToken'])->postJson('/api/v1/account/consents')->assertOk();

    expect(UserConsent::count())->toBe(2);
});

it('surfaces outstanding consents on the account payload itself', function () {
    $signIn = signIn();

    config()->set('rafeeq.legal.privacy_version', '3.1');

    $this->withToken($signIn['session']['accessToken'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.outstandingConsents', ['privacy']);

    expect(User::count())->toBe(1);
});
