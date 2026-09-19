<?php

namespace App\Domains\Identity\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\OrgType;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\PaymentMethod;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Verification\Models\TrustScore;
use App\Domains\Verification\Models\UserVerification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * One account per person (Bible §1.4) — passenger capability is always on,
 * driver capability unlocks once a `DriverProfile` is approved. There is
 * deliberately no `password` column: authentication is phone + OTP + a
 * locally-verified PIN that the server never sees (Bible §Part 2, pitfall
 * #33).
 */
#[Fillable([
    'phone_e164', 'full_name', 'public_first_name', 'profile_photo_path',
    'gender', 'date_of_birth', 'email', 'org_type', 'organization_id',
    'registered_role', 'preferred_language',
])]
// Hidden by DEFAULT so an accidental `return $user;` can never leak them
// (pitfall #21/#30). `gender` is absolute — never exposed to anyone. The
// other two are context-dependent: a Resource showing someone their OWN
// profile opts back in explicitly with ->makeVisible(), which keeps the
// exposure decision auditable in one place instead of hoping every future
// Resource remembers to strip them.
#[Hidden(['gender', 'phone_e164', 'full_name', 'email', 'date_of_birth'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'account_status' => AccountStatus::class,
            'profile_status' => ProfileStatus::class,
            'registered_role' => RegisteredRole::class,
            'org_type' => OrgType::class,
            'trust_level' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function places(): HasMany
    {
        return $this->hasMany(UserPlace::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(UserVerification::class);
    }

    public function trustScore(): HasOne
    {
        return $this->hasOne(TrustScore::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class, 'user_id');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function commuteDemands(): HasMany
    {
        return $this->hasMany(CommuteDemand::class, 'passenger_user_id');
    }

    public function seatRequests(): HasMany
    {
        return $this->hasMany(SeatRequest::class, 'passenger_user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'passenger_user_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function blockedUsers(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'blocker_user_id');
    }

    /**
     * Overrides Notifiable's own `notifications()` (which points at
     * Laravel's built-in `DatabaseNotification`) — Rafeeq uses its own
     * notification schema, not the framework's default one.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(UserStat::class);
    }

    public function isActive(): bool
    {
        return $this->account_status === AccountStatus::Active;
    }
}
