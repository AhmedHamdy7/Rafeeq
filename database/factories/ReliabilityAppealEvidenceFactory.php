<?php

namespace Database\Factories;

use App\Domains\Reliability\Models\ReliabilityAppealEvidence;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Enums\EvidenceKind;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReliabilityAppealEvidence>
 */
class ReliabilityAppealEvidenceFactory extends Factory
{
    protected $model = ReliabilityAppealEvidence::class;

    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'reliability_event_id' => ReliabilityEvent::factory(),
            'file_path' => "private/reliability/{$id}/evidence.jpg",
            'kind' => EvidenceKind::Photo,
            'file_hash' => hash('sha256', $id),
        ];
    }
}
