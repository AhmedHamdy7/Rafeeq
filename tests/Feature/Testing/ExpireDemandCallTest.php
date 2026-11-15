<?php

use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->paxToken = passenger('01112223344');
});

function saveExpirableDemand(string $token, array $overrides = []): string
{
    return test()->flushHeaders()->withToken($token)->postJson('/api/v1/commute-demands', array_merge([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'arrivalWindowStart' => '08:00:00',
        'arrivalWindowEnd' => '08:00:00',
    ], $overrides))->assertStatus(201)->json('data.id');
}

function asDemandTester(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

it('expires the caller\'s active demand, and resuming brings it back', function () {
    $id = saveExpirableDemand($this->paxToken);

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$id}/expire")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'EXPIRED');

    expect(CommuteDemand::query()->findOrFail($id)->expires_at->isPast())->toBeTrue();

    asDemandTester($this->paxToken)->getJson("/api/v1/commute-demands/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'EXPIRED');

    asDemandTester($this->paxToken)->patchJson("/api/v1/commute-demands/{$id}", ['paused' => false])
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');

    expect(CommuteDemand::query()->findOrFail($id)->expires_at->isFuture())->toBeTrue();
});

it('leaves the caller\'s other demands alone', function () {
    $target = saveExpirableDemand($this->paxToken);
    $other = saveExpirableDemand($this->paxToken, ['daysMask' => 1]);

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$target}/expire")->assertOk();

    expect(CommuteDemand::query()->findOrFail($other)->status)->toBe(CommuteDemandStatus::Active);
});

it('answers 409 when the demand is not active', function () {
    $id = saveExpirableDemand($this->paxToken);

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$id}/expire")->assertOk();

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$id}/expire")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_DEMAND_NOT_EDITABLE')
        ->assertJsonPath('error.fields.status.0', 'EXPIRED');

    $paused = saveExpirableDemand($this->paxToken, ['daysMask' => 1]);
    asDemandTester($this->paxToken)->patchJson("/api/v1/commute-demands/{$paused}", ['paused' => true])->assertOk();

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$paused}/expire")
        ->assertStatus(409)
        ->assertJsonPath('error.fields.status.0', 'PAUSED');
});

it('answers 404 for somebody else\'s or a cancelled demand', function () {
    $id = saveExpirableDemand($this->paxToken);
    $other = passenger('01112229999', device: 'pax-other');

    asDemandTester($other)->postJson("/api/v1/testing/commute-demands/{$id}/expire")->assertNotFound();

    asDemandTester($this->paxToken)->deleteJson("/api/v1/commute-demands/{$id}")->assertOk();

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$id}/expire")->assertNotFound();
});

it('is not there when the testing API is off', function () {
    config(['rafeeq.testing.api' => false]);
    $id = saveExpirableDemand($this->paxToken);

    asDemandTester($this->paxToken)->postJson("/api/v1/testing/commute-demands/{$id}/expire")->assertNotFound();
});
