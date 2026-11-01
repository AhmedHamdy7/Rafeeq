<?php

namespace Database\Seeders;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Identity\Actions\EnrollInitialRoleAction;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * The accounts an Apple or Google reviewer signs into (F1-1), on whatever database the store build
 * points at — production included, which is why this is separate from `DemoDataSeeder` and never
 * builds a world of its own.
 *
 * Two people, both obviously fictional:
 *
 *   · **the review account** at `RAFEEQ_REVIEW_PHONE` — a verified passenger AND an approved driver
 *     with a reviewed car and a published commute, so the reviewer can open both sides of the app
 *     from one sign-in; with a confirmed seat on an upcoming day so Home is not empty;
 *   · **a second driver** with a published commute on the same corridor, so search finds a result
 *     and the seat above has somebody to ride with. Nobody signs in as her.
 *
 * Idempotent: an account that already exists is left alone, so running it on every deploy is safe.
 */
class ReviewAccountsSeeder extends DemoDataSeeder
{
    // A number in the block the demo drivers use — owned by nobody.
    public const string SECOND_DRIVER_PHONE = '+201055550099';

    public function run(): void
    {
        $reviewPhone = (string) config('rafeeq.auth.otp.review_phone');

        if ($reviewPhone === '' || (string) config('rafeeq.auth.otp.review_code') === '') {
            throw new RuntimeException('Set RAFEEQ_REVIEW_PHONE and RAFEEQ_REVIEW_OTP first: without them nobody can sign in as the review account.');
        }

        $reviewPhone = PhoneNumber::fromRaw($reviewPhone)->e164;

        $admin = AdminUser::query()->first()
            ?? throw new RuntimeException('No admin user yet. Create one first: php artisan admin:create');

        $corridor = Corridor::query()->with('originPlace', 'destinationPlace')->first()
            ?? throw new RuntimeException('No corridor yet. The review commutes need a corridor with two places.');

        [$origin, $destination] = [$corridor->originPlace, $corridor->destinationPlace];

        if ($origin === null || $destination === null) {
            throw new RuntimeException("Corridor {$corridor->id} has no origin or destination place.");
        }

        $second = User::query()->where('phone_e164', self::SECOND_DRIVER_PHONE)->first()
            ?? $this->seedDriverWithCommute(
                ['name' => 'سارة رفيق', 'first' => 'سارة', 'phone' => self::SECOND_DRIVER_PHONE, 'time' => '07:20:00', 'tier' => VehicleTier::Standard, 'seats' => 3, 'plate' => 'ر ف ق 1', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'أبيض', 'trips' => 24, 'onTime' => 96.0, 'audience' => CommuteAudience::AnyVerified],
                null, $admin, $corridor, $origin, $destination,
            );

        $review = User::query()->where('phone_e164', $reviewPhone)->first();

        if ($review === null) {
            $review = $this->seedDriverWithCommute(
                ['name' => 'مراجعة رفيق', 'first' => 'مراجعة', 'phone' => $reviewPhone, 'time' => '08:00:00', 'tier' => VehicleTier::Comfort, 'seats' => 3, 'plate' => 'ر ف ق 2', 'make' => 'Hyundai', 'model' => 'Elantra', 'colour' => 'رمادي', 'trips' => 12, 'onTime' => 100.0, 'audience' => CommuteAudience::AnyVerified],
                null, $admin, $corridor, $origin, $destination,
            );

            // Both sides on one account, so the reviewer can switch modes without a second sign-in.
            EnrollInitialRoleAction::grant($review, [Capability::Passenger, Capability::Driver]);
        }

        // The real generator, so the review commutes have days exactly as production makes them.
        Artisan::call('commutes:generate-trips');

        $hasSeat = Booking::query()->where('passenger_user_id', $review->id)->exists();

        if (! $hasSeat) {
            $this->seatOn($review, ScheduledTrip::query()
                ->whereHas('commuteOffer', fn ($offer) => $offer->where('driver_profile_id', $second->id))
                ->where('status', ScheduledTripStatus::Scheduled)
                ->where('departure_at', '>', now())
                ->whereColumn('seats_taken', '<', 'seats_total')
                ->orderBy('departure_at')
                ->with('commuteOffer')
                ->first());
        }

        $this->command?->info("Review account ready at {$reviewPhone}: sign in with RAFEEQ_REVIEW_OTP.");
    }
}
