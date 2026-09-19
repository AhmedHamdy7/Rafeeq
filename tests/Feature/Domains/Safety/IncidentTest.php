<?php

use App\Domains\Safety\Enums\EvidenceKind;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentEvidence;

it('reports open state across the non-terminal statuses', function () {
    $open = Incident::factory()->create(['status' => IncidentStatus::Open]);
    $escalated = Incident::factory()->create(['status' => IncidentStatus::Escalated]);
    $closed = Incident::factory()->create(['status' => IncidentStatus::Closed]);

    expect($open->isOpen())->toBeTrue()
        ->and($escalated->isOpen())->toBeTrue()
        ->and($closed->isOpen())->toBeFalse();
});

it('loads its evidence', function () {
    $incident = Incident::factory()
        ->has(IncidentEvidence::factory()->count(2), 'evidence')
        ->create();

    expect($incident->evidence)->toHaveCount(2);
});

it('hides the evidence file path from serialization', function () {
    $evidence = IncidentEvidence::factory()->create();

    expect($evidence->toArray())->not->toHaveKey('file_path');
});

it('prevents evidence from being deleted but still allows updates', function () {
    $evidence = IncidentEvidence::factory()->create();

    $evidence->update(['kind' => EvidenceKind::Video]);
    expect($evidence->fresh()->kind)->toBe(EvidenceKind::Video);

    $evidence->delete();
})->throws(RuntimeException::class);
