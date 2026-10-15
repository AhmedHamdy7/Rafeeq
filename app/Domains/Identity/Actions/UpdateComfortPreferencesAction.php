<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ComfortPreference;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserComfortPreference;

/**
 * Saving what somebody wants the inside of the car to be like, for one mode.
 *
 * 🔒 Grants nothing and filters nothing. Four stored answers, and the only thing reading them is
 * the screen that shows them back — see the migration for why that restraint is deliberate rather
 * than unfinished.
 */
final readonly class UpdateComfortPreferencesAction
{
    /** The only fields this will write, mapped from payload name to column. */
    private const array FIELDS = [
        'smoking' => 'smoking',
        'music' => 'music',
        'conversation' => 'conversation',
        'airConditioning' => 'air_conditioning',
    ];

    /**
     * @param  array<string, mixed>  $attributes  already validated upstream
     */
    public function execute(User $user, Capability $mode, array $attributes): UserComfortPreference
    {
        $preference = UserComfortPreference::forUserAndMode($user, $mode);

        /*
         * An allow-list rather than a loop over whatever arrived. v3 says "validate only agreed
         * fields", and this is the second half of honouring that: the FormRequest decides what is
         * acceptable, and this decides what is writable. A field added to the payload later cannot
         * reach a column until somebody adds it here on purpose.
         */
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $preference->{$column} = ComfortPreference::from((string) $attributes[$field]);
            }
        }

        $preference->save();

        return $preference;
    }
}
