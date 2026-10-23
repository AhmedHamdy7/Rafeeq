<?php

namespace App\Domains\Outbox\Models;

use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Support\Outbox;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One announcement waiting to go out, or already gone (Feature 03 v5's `outbox_events`).
 *
 * No `$fillable`: rows are written by {@see Outbox} inside the same
 * transaction as the fact they describe, and a mass-assignable `channel` would let a request choose
 * who hears about somebody else's seat.
 */
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory, HasUlids, MassPrunable;

    protected function casts(): array
    {
        return [
            'type' => OutboxEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Not yet on the broker, oldest first.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('published_at')->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Published rows age out; unpublished ones never do.
     *
     * 🔴 The condition is the point. An outbox exists so that nothing is lost between a fact and
     * its announcement, and pruning by age alone would quietly delete the one row that proves the
     * mechanism failed — which is the only row anybody would want to find.
     *
     * Published events are a delivery log and nothing is read off them after the fact, so they go
     * on the same fourteen-day clock the push log uses.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<', now()->subDays((int) config('rafeeq.outbox.keep_published_days')));
    }
}
