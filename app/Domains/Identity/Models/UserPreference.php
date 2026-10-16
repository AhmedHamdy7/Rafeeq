<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ThemePreference;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cross-device UI state, one row per account.
 *
 * 🔴 None of this is authorization, and the distinction has to survive future edits:
 * `last_active_mode` says which home screen to open, not what the person may do. What she may do
 * is {@see UserCapability}, checked per request. A client that tampers with its stored mode gets a
 * different screen and exactly no additional permission.
 */
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    /**
     * 1:1 with users — the primary key IS the `user_id` foreign key, so `HasUlids` is
     * deliberately NOT used, exactly as on {@see UserStat}. That trait generates a fresh id for
     * the key column on create, which here would overwrite the foreign key with a random ULID and
     * attach the row to nobody.
     */
    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'last_active_mode' => Capability::class,
            'theme' => ThemePreference::class,
            'onboarding_seen_at' => 'datetime',
            'driver_walkthrough_seen_at' => 'datetime',
            // Lists of `CommuteRuleKey` values. See `comfortRulesFor()`.
            'passenger_comfort_rules' => 'array',
            'driver_comfort_rules' => 'array',
        ];
    }

    /**
     * The comfort chips this person has saved for one mode, as `CommuteRuleKey` values.
     *
     * 🔴 The SAME vocabulary the matcher already uses, deliberately. A commute carries
     * `commute_rules` and a search carries `requiredRules`, both drawn from `CommuteRuleKey`, and
     * they feed `comfort_score` together. Storing a second set of words for the same six chips
     * would mean the mobile team had two ways to say "quiet ride" and only one of them did
     * anything.
     *
     * `null` and `[]` are different and both are kept: null is "never chosen", which must never
     * narrow a search, while `[]` is "I want none of them" — a real answer somebody gave.
     *
     * @return array<int, string>|null
     */
    public function comfortRulesFor(Capability $mode): ?array
    {
        return $mode === Capability::Driver
            ? $this->driver_comfort_rules
            : $this->passenger_comfort_rules;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This person's preference row, created in memory if they have none yet.
     *
     * 🔴 Exists because `firstOrNew(['user_id' => ...])` MASS-ASSIGNS the key, and this model has
     * no `$fillable` on purpose — so under strict mode that throws
     * `MassAssignmentException` instead of quietly working. Setting the key directly is both the
     * fix and the honest expression of it: the owner is not user input, it is the authenticated
     * caller.
     *
     * Not saved here. The caller decides whether anything actually changed, so a read-only path
     * never writes an empty row.
     */
    public static function forUser(User $user): self
    {
        $preference = self::query()->find($user->getKey());

        if ($preference !== null) {
            return $preference;
        }

        $preference = new self;
        $preference->user_id = $user->getKey();

        return $preference;
    }

    /**
     * Whether the driver walkthrough still needs to run.
     *
     * Feature 01 v3 §4: a Driver sees it before the Driver home the first time, and somebody who
     * enrolled as Both sees it only on first Driver activation — never on the passenger side. So
     * this answers only "has it been seen", and the caller decides whether the person is about to
     * enter the driver surface at all.
     */
    public function needsDriverWalkthrough(): bool
    {
        return $this->driver_walkthrough_seen_at === null;
    }
}
