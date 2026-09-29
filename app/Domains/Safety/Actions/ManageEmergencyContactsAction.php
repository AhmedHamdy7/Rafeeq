<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\PhoneNumber;

/**
 * Trusted contacts — who gets told when something goes wrong (Chapter 10).
 *
 * 🔒 This list is the most dangerous small feature in the product, and the danger runs the opposite
 * way from how it first looks. It is not "who can I call for help": it is **who receives this
 * person's live location**. An abusive partner who gets themselves onto somebody's contact list,
 * with `auto_share_trips` on, has a lawful-looking feed of where she goes every morning.
 *
 * Which is why:
 *
 * - The list is **capped**. An unbounded one is a way to broadcast somebody's movements to a crowd.
 * - A contact is **only ever visible to the person who added it**, and their phone number is hidden
 *   on the model by default rather than by each caller remembering.
 * - Removal is **immediate and unconditional**. There is no confirmation flow, no cooling-off, no
 *   "are you sure" the server enforces: somebody removing a contact may be doing it quickly and
 *   quietly, and every extra step is a step taken where they may be watched.
 * - `verified_at` exists because a number nobody confirmed is a contact who will never be reached.
 *   Verification itself needs an OTP to that number (Phase 12); until then it stays null and the
 *   API says so rather than implying the contact works.
 */
final readonly class ManageEmergencyContactsAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(User $user, array $attributes): EmergencyContact
    {
        $phone = PhoneNumber::fromRaw($attributes['phone']);

        $this->assertRoomForAnother($user);
        $this->assertNotAlreadyListed($user, $phone->e164);

        $contact = new EmergencyContact;

        $contact->fill([
            'user_id' => $user->id,
            'name' => $attributes['name'],
            'phone_e164' => $phone->e164,
            'relationship' => $attributes['relationship'] ?? null,
            'auto_share_trips' => $attributes['autoShareTrips'] ?? false,
            'is_guardian' => $attributes['isGuardian'] ?? false,
        ]);

        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(EmergencyContact $contact, array $attributes): EmergencyContact
    {
        $changes = [];

        foreach (['name' => 'name', 'relationship' => 'relationship',
            'autoShareTrips' => 'auto_share_trips', 'isGuardian' => 'is_guardian'] as $input => $column) {
            if (array_key_exists($input, $attributes)) {
                $changes[$column] = $attributes[$input];
            }
        }

        if (array_key_exists('phone', $attributes)) {
            $phone = PhoneNumber::fromRaw($attributes['phone']);

            $this->assertNotAlreadyListed($contact->user, $phone->e164, exceptId: $contact->id);

            $changes['phone_e164'] = $phone->e164;

            /*
             * 🔒 A changed number is an unverified number. Carrying the old confirmation across
             * would mean a contact that says "verified" about a number nobody ever reached — and if
             * the change was made by somebody else on an unlocked phone, that badge is the thing
             * that would stop anybody looking twice.
             */
            $changes['verified_at'] = null;
        }

        $contact->forceFill($changes)->save();

        return $contact;
    }

    /**
     * 🔒 Immediate and unconditional. See the class note: somebody removing a contact may be doing
     * it quickly and quietly, and a confirmation step is a step taken while they may be watched.
     */
    public function remove(EmergencyContact $contact): void
    {
        $contact->delete();
    }

    private function assertRoomForAnother(User $user): void
    {
        $existing = EmergencyContact::query()->where('user_id', $user->id)->count();

        if ($existing >= SafetySettings::maxEmergencyContacts()) {
            throw DomainException::of(ErrorCode::EmergencyContactLimitReached, fields: [
                'limit' => [(string) SafetySettings::maxEmergencyContacts()],
            ]);
        }
    }

    /**
     * The same number twice would mean two rows to remove in a hurry, and a removal that looks
     * like it worked while the second one keeps receiving.
     */
    private function assertNotAlreadyListed(User $user, string $e164, ?string $exceptId = null): void
    {
        $duplicate = EmergencyContact::query()
            ->where('user_id', $user->id)
            ->where('phone_e164', $e164)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($duplicate) {
            throw DomainException::of(ErrorCode::EmergencyContactDuplicate);
        }
    }
}
