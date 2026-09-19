<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\OtpStatus;
use Database\Factories\OtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * No FK to `users` on purpose — a challenge exists before we know whether
 * the phone has an account (Bible §4, group ①).
 */
#[Fillable([
    'phone_e164', 'purpose', 'code_hash', 'expires_at', 'max_attempts',
    'device_fingerprint_hash', 'ip_hash',
])]
#[Hidden(['code_hash'])]
class OtpChallenge extends Model
{
    /** @use HasFactory<OtpChallengeFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'status' => OtpStatus::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'resend_count' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasAttemptsRemaining(): bool
    {
        return $this->attempt_count < $this->max_attempts;
    }
}
