<?php

namespace App\Domains\Safety\Models;

use App\Domains\Safety\Enums\EvidenceKind;
use App\Domains\Shared\Concerns\PreventsDeletion;
use Database\Factories\IncidentEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 🔒 Chain of custody — never deleted (Bible §4, group ⑫). */
#[Fillable(['incident_id', 'file_path', 'kind', 'file_hash', 'purge_after'])]
#[Hidden(['file_path'])]
class IncidentEvidence extends Model
{
    /** @use HasFactory<IncidentEvidenceFactory> */
    use HasFactory, HasUlids, PreventsDeletion;

    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'purge_after' => 'date',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
