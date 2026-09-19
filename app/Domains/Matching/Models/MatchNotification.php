<?php

namespace App\Domains\Matching\Models;

use App\Domains\Commute\Models\CommuteOffer;
use Database\Factories\MatchNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commute_demand_id', 'commute_offer_id', 'score', 'delivered_at'])]
class MatchNotification extends Model
{
    /** @use HasFactory<MatchNotificationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'delivered_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function commuteDemand(): BelongsTo
    {
        return $this->belongsTo(CommuteDemand::class);
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }
}
