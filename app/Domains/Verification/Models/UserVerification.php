<?php

namespace App\Domains\Verification\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationMethod;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use Database\Factories\UserVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// `status` is deliberately NOT fillable (pitfall #51): approval is a
// privileged transition an admin Action performs by assigning the attribute
// explicitly — never something that can ride in on a mass-assigned request.
#[Fillable(['user_id', 'type', 'method', 'expires_at'])]
class UserVerification extends Model
{
    /** @use HasFactory<UserVerificationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'status' => VerificationStatus::class,
            'method' => VerificationMethod::class,
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(IdentityDocument::class);
    }

    public function isApproved(): bool
    {
        return $this->status === VerificationStatus::Approved;
    }
}
