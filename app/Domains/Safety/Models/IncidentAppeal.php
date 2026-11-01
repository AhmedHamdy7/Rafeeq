<?php

namespace App\Domains\Safety\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\IncidentAppealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reporter asking for the decision on her report to be looked at again (HISTORY-10). See the
 * migration.
 *
 * No `$fillable`: written only by `AppealIncidentAction`, which decides the round and counts the
 * evidence itself — neither is the reporter's to state.
 */
class IncidentAppeal extends Model
{
    /** @use HasFactory<IncidentAppealFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'evidence_count' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
