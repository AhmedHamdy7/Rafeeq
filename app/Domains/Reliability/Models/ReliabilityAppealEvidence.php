<?php

namespace App\Domains\Reliability\Models;

use App\Domains\Safety\Enums\EvidenceKind;
use App\Domains\Shared\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reliability_event_id', 'file_path', 'kind', 'file_hash', 'purge_after'])]
#[Hidden(['file_path'])]
class ReliabilityAppealEvidence extends Model
{
    use HasUlids, PreventsDeletion;

    protected $table = 'reliability_appeal_evidence';

    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'purge_after' => 'date',
            'purged_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ReliabilityEvent::class, 'reliability_event_id');
    }
}
