<?php

namespace App\Domains\Commute\Models;

use App\Domains\Commute\Enums\CommuteRuleKey;
use Database\Factories\CommuteRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commute_offer_id', 'rule_key', 'rule_value'])]
class CommuteRule extends Model
{
    /** @use HasFactory<CommuteRuleFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'rule_key' => CommuteRuleKey::class,
            'rule_value' => 'boolean',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }
}
