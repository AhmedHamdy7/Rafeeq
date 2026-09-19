<?php

namespace App\Domains\Safety\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\BlockedUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['blocker_user_id', 'blocked_user_id', 'reason'])]
class BlockedUser extends Model
{
    /** @use HasFactory<BlockedUserFactory> */
    use HasFactory, HasUlids;

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_user_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    /**
     * 🔴 Pitfall #27: must be checked in both directions everywhere a block
     * matters (matching, messaging) — this is the canonical way to do it.
     */
    public static function existsBetween(string $userIdA, string $userIdB): bool
    {
        return static::query()
            ->where(function ($query) use ($userIdA, $userIdB) {
                $query->where('blocker_user_id', $userIdA)->where('blocked_user_id', $userIdB);
            })
            ->orWhere(function ($query) use ($userIdA, $userIdB) {
                $query->where('blocker_user_id', $userIdB)->where('blocked_user_id', $userIdA);
            })
            ->exists();
    }
}
