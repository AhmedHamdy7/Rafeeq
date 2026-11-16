<?php

namespace App\Domains\Safety\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\AutoShareMessageStatus;
use App\Domains\Trip\Models\TripSession;
use Database\Factories\AutoShareMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'kind', 'body', 'user_id', 'trip_session_id', 'live_share_id', 'emergency_contact_id', 'channel',
    'to_e164', 'status', 'failure_reason', 'preview_url', 'sent_at',
])]
#[Hidden(['to_e164', 'preview_url', 'body'])]
class AutoShareMessage extends Model
{
    /** @use HasFactory<AutoShareMessageFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => AutoShareMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }

    public function liveShare(): BelongsTo
    {
        return $this->belongsTo(LiveShare::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmergencyContact::class, 'emergency_contact_id');
    }
}
