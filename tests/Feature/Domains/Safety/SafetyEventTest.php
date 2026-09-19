<?php

use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;

it('cannot be deleted', function () {
    $event = SafetyEvent::factory()->create();

    $event->delete();
})->throws(RuntimeException::class);

it('allows updates unlike strictly append-only tables', function () {
    $event = SafetyEvent::factory()->create();

    $event->update(['severity' => 'low']);

    expect($event->fresh()->severity->value)->toBe('low');
});

it('measures SOS response time from creation to first touch', function () {
    $event = SafetyEvent::factory()->create();
    $sos = SosEvent::factory()->resolved()->create(['safety_event_id' => $event->id]);

    expect($sos->responseSeconds())->toBe(35);
});

it('supports a discreet (silent) alert', function () {
    $sos = SosEvent::factory()->create(['is_discreet' => true]);

    expect($sos->is_discreet)->toBeTrue();
});
