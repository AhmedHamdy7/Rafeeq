<?php

use App\Domains\Driver\Models\VehicleDocument;

it('hides the private file path from serialization', function () {
    $document = VehicleDocument::factory()->create();

    expect($document->toArray())->not->toHaveKey('file_path');
});

it('belongs to a vehicle', function () {
    $document = VehicleDocument::factory()->create();

    expect($document->vehicle)->not->toBeNull();
});
