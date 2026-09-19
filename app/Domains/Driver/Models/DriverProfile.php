<?php

namespace App\Domains\Driver\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Models\DriverFeeLedger;
use App\Domains\Payment\Models\Payout;
use Database\Factories\DriverProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

/**
 * One profile per person (Bible §4, group ③) — the primary key IS
 * `users.id`. `national_id` / `licence_number` are virtual attributes: they
 * transparently split into an encrypted column (reversible, for admin
 * review) and a sha256 hash column (for duplicate detection without ever
 * decrypting — see the Bible's "why both?" note).
 */
#[Fillable(['licence_expiry'])]
#[Hidden(['national_id_encrypted', 'national_id_hash', 'licence_number_encrypted', 'licence_number_hash'])]
class DriverProfile extends Model
{
    /** @use HasFactory<DriverProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'status' => DriverProfileStatus::class,
            // national_id_encrypted / licence_number_encrypted are NOT cast
            // as 'encrypted' here: an Attribute::set() that returns several
            // keys (below) merges them straight into the raw attributes
            // array, bypassing each key's own cast entirely. Composing with
            // a column cast would silently persist plaintext. Encryption is
            // done explicitly in the accessors instead.
            'licence_expiry' => 'date',
            'verified_at' => 'datetime',
            'completed_trips_count' => 'integer',
            'cancellation_rate' => 'decimal:2',
            'on_time_rate' => 'decimal:2',
        ];
    }

    protected function nationalId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->national_id_encrypted ? Crypt::decryptString($this->national_id_encrypted) : null,
            set: fn (string $value) => [
                'national_id_encrypted' => Crypt::encryptString($value),
                'national_id_hash' => hash('sha256', $value),
            ],
        );
    }

    protected function licenceNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->licence_number_encrypted ? Crypt::decryptString($this->licence_number_encrypted) : null,
            set: fn (string $value) => [
                'licence_number_encrypted' => Crypt::encryptString($value),
                'licence_number_hash' => hash('sha256', $value),
            ],
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'driver_profile_id', 'user_id');
    }

    public function commuteOffers(): HasMany
    {
        return $this->hasMany(CommuteOffer::class, 'driver_profile_id', 'user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_profile_id', 'user_id');
    }

    public function feeLedgerEntries(): HasMany
    {
        return $this->hasMany(DriverFeeLedger::class, 'driver_profile_id', 'user_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(DriverBalance::class, 'driver_profile_id', 'user_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'driver_profile_id', 'user_id');
    }

    public function activeVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'driver_profile_id', 'user_id')->where('is_active', true);
    }

    public function isApproved(): bool
    {
        return $this->status === DriverProfileStatus::Approved;
    }

    public function hasValidLicence(): bool
    {
        return $this->licence_expiry !== null && $this->licence_expiry->isFuture();
    }
}
