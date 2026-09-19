<?php

namespace App\Domains\Rating\Models;

use App\Domains\Rating\Enums\RatingTagValue;
use Database\Factories\RatingTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rating_id', 'tag'])]
class RatingTag extends Model
{
    /** @use HasFactory<RatingTagFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'tag' => RatingTagValue::class,
        ];
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }
}
