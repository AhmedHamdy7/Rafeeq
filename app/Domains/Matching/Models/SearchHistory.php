<?php

namespace App\Domains\Matching\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\SearchHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One search she ran, kept so the box can prefill itself (Feature 03 v5).
 *
 * 🔒 No `$fillable`. A `user_id` a client could set would let somebody write into another person's
 * history — and the thing being written is a pair of coordinates, so the attack is "put my address
 * in her recent searches" or, read the other way round, a probe for whose history exists.
 */
class SearchHistory extends Model
{
    /** @use HasFactory<SearchHistoryFactory> */
    use HasFactory, HasUlids, MassPrunable;

    protected $table = 'search_history';

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'searched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Her list, most recently run first.
     *
     * Ordered on `searched_at` and never on `created_at`: a route she searches every week should sit
     * at the top, and it was first seen months ago.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->orderByDesc('searched_at')
            // Standard #42: a tiebreak, because `searched_at` has second precision and two searches
            // in one second would otherwise order differently on every page.
            ->orderByDesc('id');
    }

    /**
     * 🔒 Ages out by itself, measured from when the search was FIRST made.
     *
     * The alternative — pruning on `searched_at` — would mean a route she keeps re-running is kept
     * for ever, and this is the one table where "kept for ever" is a privacy decision rather than a
     * storage one. It is also why the two columns are separate: the list reads one and retention
     * reads the other.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays((int) config('rafeeq.retention.search_history_days')),
        );
    }
}
