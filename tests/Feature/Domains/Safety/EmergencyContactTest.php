<?php

use App\Domains\Safety\Models\EmergencyContact;

it('reports verification state', function () {
    $verified = EmergencyContact::factory()->create();
    $unverified = EmergencyContact::factory()->create(['verified_at' => null]);

    expect($verified->isVerified())->toBeTrue()
        ->and($unverified->isVerified())->toBeFalse();
});

it('can be flagged as a guardian with elevated access', function () {
    $contact = EmergencyContact::factory()->create();

    expect($contact->is_guardian)->toBeTrue();
});
