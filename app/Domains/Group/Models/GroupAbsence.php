<?php

namespace App\Domains\Group\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\GroupAbsenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commute_group_id', 'user_id', 'from_date', 'to_date', 'reason', 'releases_seat'])]
class GroupAbsence extends Model
{
    /** @use HasFactory<GroupAbsenceFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'releases_seat' => 'boolean',
        ];
    }

    public function commuteGroup(): BelongsTo
    {
        return $this->belongsTo(CommuteGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
