<?php

namespace Database\Factories;

use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentEvidence>
 */
class IncidentEvidenceFactory extends Factory
{
    protected $model = IncidentEvidence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'incident_id' => Incident::factory(),
            'file_path' => "private/incidents/{$id}/evidence.jpg",
            'kind' => 'photo',
            'file_hash' => hash('sha256', $id),
        ];
    }
}
