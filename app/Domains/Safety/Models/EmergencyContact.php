<?php

namespace App\Domains\Safety\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\EmergencyContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'phone_e164', 'relationship', 'auto_share_trips', 'is_guardian'])]
// A guardian's number is only ever shown back to the person who added it —
// hidden by default, revealed explicitly by that one Resource.
#[Hidden(['phone_e164'])]
class EmergencyContact extends Model
{
    /** @use HasFactory<EmergencyContactFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'auto_share_trips' => 'boolean',
            'is_guardian' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function liveShares(): HasMany
    {
        return $this->hasMany(LiveShare::class, 'shared_with_contact_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
