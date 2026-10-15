<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ComfortPreference;
use Database\Factories\UserComfortPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person wants the inside of the car to be like, in one mode.
 *
 * 🔴 Nothing reads this to decide a match, and that is deliberate — see the migration. It is stored
 * and returned, and that is all it does today.
 *
 * No `$fillable`: the four answers are written by `UpdateComfortPreferencesAction` from a validated
 * payload, and `user_id`/`mode` are facts about who is calling rather than fields they may send.
 */
class UserComfortPreference extends Model
{
    /** @use HasFactory<UserComfortPreferenceFactory> */
    use HasFactory, HasUlids;

    /**
     * Mirrors the column defaults, so an unsaved row reads `either` rather than `null`.
     *
     * Needed because `forUserAndMode()` deliberately does not save: without this, a GET from
     * somebody who has never opened the screen would return four nulls, and every client would
     * have to know that null means "no preference" — a rule nobody would guess and everybody
     * would eventually render as "no".
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'smoking' => 'either',
        'music' => 'either',
        'conversation' => 'either',
        'air_conditioning' => 'either',
    ];

    protected function casts(): array
    {
        return [
            'mode' => Capability::class,
            'smoking' => ComfortPreference::class,
            'music' => ComfortPreference::class,
            'conversation' => ComfortPreference::class,
            'air_conditioning' => ComfortPreference::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This person's row for one mode, created in memory if they have none yet.
     *
     * Not saved here: a GET must not write a row. Somebody who has never opened the screen should
     * read back the defaults without us recording that they looked.
     */
    public static function forUserAndMode(User $user, Capability $mode): self
    {
        $existing = self::query()
            ->where('user_id', $user->getKey())
            ->where('mode', $mode->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $fresh = new self;
        $fresh->user_id = $user->getKey();
        $fresh->mode = $mode;

        return $fresh;
    }
}
