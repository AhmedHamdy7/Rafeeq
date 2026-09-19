<?php

namespace App\Domains\Verification\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\PublicTrustTier;
use Database\Factories\TrustScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 with users. `score` is an internal 0-100 number — never exposed;
 * `public_tier` is the only thing the API is allowed to return.
 */
#[Fillable(['user_id', 'score', 'public_tier', 'components', 'computed_at'])]
#[Hidden(['score', 'components'])]
class TrustScore extends Model
{
    /** @use HasFactory<TrustScoreFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'public_tier' => PublicTrustTier::class,
            'components' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
