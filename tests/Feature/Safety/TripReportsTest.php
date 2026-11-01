<?php

use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\SafetyEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Report a problem / My reports, as the Trips history uses them (the HISTORY group, 2026-11-01).
 *
 * The app's report form offers six reasons — Safety concern, Unsafe driving, Harassment, False
 * arrival, No-show, Other — and the past trip shows "My reports" for that one journey. These pin
 * what the server does with each of those, so the form's wording and the queue's ordering cannot
 * drift apart.
 */
beforeEach(function () {
    Storage::fake('documents');

    fakeOtpSender();
    $this->token = signIn('01112223344')['session']['accessToken'];
});

/**
 * 🔴 HISTORY-9. "Safety concern" was sent as `other`, which the queue ranks medium — below what a
 * passenger means by "safety". It has its own category now, read the same morning.
 */
it('ranks a safety concern high, with a four-hour deadline', function () {
    $incident = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'safety_concern',
        'description' => 'حسيت إني مش في أمان في الرحلة.',
    ])->assertStatus(201)->json('data');

    expect($incident['category'])->toBe('safety_concern')
        ->and($incident['severity'])->toBe('high')
        ->and(SafetyEvent::sole()->severity)->toBe(SafetySeverity::High);

    $hours = now()->diffInMinutes(Carbon::parse($incident['slaDueAt'])) / 60;

    expect(round($hours))->toBe(4.0);
});

it('ranks a safety concern above other', function () {
    $other = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201)->json('data');

    $concern = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'safety_concern'])
        ->assertStatus(201)->json('data');

    expect($other['severity'])->toBe('medium')
        ->and($concern['slaDueAt'] < $other['slaDueAt'])->toBeTrue();
});

/**
 * HISTORY-9's second half: `false_arrival` (X-7) is live, not planned. Medium — it is a dispute
 * about a fee (TRIP-17), and what it does to her no-show is pinned in IncidentMoneyTest.
 */
it('accepts a false arrival report today', function () {
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'false_arrival'])
        ->assertStatus(201)
        ->assertJsonPath('data.category', 'false_arrival')
        ->assertJsonPath('data.severity', 'medium');
});
