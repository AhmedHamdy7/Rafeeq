<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\ConsentDocumentType;
use App\Domains\Identity\Enums\ConsentSource;
use Database\Factories\UserConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why not `users.accepted_terms = true`? Terms change over time — legally
 * we must know exactly which version a person accepted and when (Bible §4).
 */
#[Fillable(['user_id', 'document_type', 'document_version', 'accepted_at', 'source'])]
class UserConsent extends Model
{
    /** @use HasFactory<UserConsentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'document_type' => ConsentDocumentType::class,
            'source' => ConsentSource::class,
            'accepted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
