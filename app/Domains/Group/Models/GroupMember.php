<?php

namespace App\Domains\Group\Models;

use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commute_group_id', 'user_id', 'role', 'committed_days_mask', 'joined_at'])]
class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'role' => GroupMemberRole::class,
            'status' => GroupMemberStatus::class,
            'committed_days_mask' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'notice_given_at' => 'datetime',
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

    public function committedDaysMask(): ?DaysMask
    {
        return $this->committed_days_mask !== null ? DaysMask::fromBits($this->committed_days_mask) : null;
    }

    public function isActive(): bool
    {
        return $this->status === GroupMemberStatus::Active;
    }
}
