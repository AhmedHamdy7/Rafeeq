<?php

namespace Database\Seeders;

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Enums\CorridorStatus;
use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Enums\CommuteGroupStatus;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\OrgType;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Verification\Enums\PublicTrustTier;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\TrustScore;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The reference scenario from Engineering Bible §Part 2, built once every
 * Phase 1 group exists: Nour (an approved driver) publishes Rehab → Smart
 * Village; Mariam (a verified passenger) requests and is approved for a
 * trial seat; a corridor, a verification queue, and one open safety case
 * round out a realistic starting state for manual QA and demos.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedAdminRoles();

        $admin = $this->seedAdmin();
        $organization = $this->seedOrganization();
        [$originPlace, $destinationPlace] = $this->seedPlaces();
        $corridor = $this->seedCorridor($originPlace, $destinationPlace);

        $nour = $this->seedNour($organization, $admin);
        $mariam = $this->seedMariam($organization);

        $offer = $this->seedCommuteOffer($nour, $corridor, $originPlace, $destinationPlace);
        $schedule = $this->seedSchedule($offer);
        $this->seedRules($offer);
        $group = $this->seedGroup($offer, $nour);
        $trip = $this->seedScheduledTrip($offer, $schedule);

        $this->seedMariamsBooking($mariam, $offer, $trip, $group);
        $this->seedVerificationQueue($admin);
        $this->seedOpenSafetyCase($mariam, $nour);
    }

    /** The 6 RBAC roles from Bible §Group 13 — must exist before assignRole() works. */
    private function seedAdminRoles(): void
    {
        foreach (AdminRole::cases() as $role) {
            Role::findOrCreate($role->value, 'admin');
        }
    }

    private function seedAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'name' => 'Omar — Verification Lead',
            'email' => 'omar@rafeeq.example',
        ]);
        $admin->assignRole(AdminRole::Verification->value);

        return $admin;
    }

    private function seedOrganization(): Organization
    {
        return Organization::factory()->create([
            'name' => 'Smart Village',
            'name_ar' => 'القرية الذكية',
            'email_domain' => 'smartvillage.com.eg',
        ]);
    }

    /**
     * @return array{0: Place, 1: Place}
     */
    private function seedPlaces(): array
    {
        $origin = Place::factory()->create([
            'name' => 'Rehab Gate 2',
            'name_ar' => 'الرحاب بوابة ٢',
            'type' => PlaceType::CompoundGate,
            'point' => new Coordinate(lat: 30.0602, lng: 31.4915),
            'lat' => 30.0602,
            'lng' => 31.4915,
            'district' => 'القاهرة الجديدة',
        ]);

        $destination = Place::factory()->create([
            'name' => 'Smart Village B6',
            'name_ar' => 'القرية الذكية مبنى B6',
            'type' => PlaceType::Office,
            'point' => new Coordinate(lat: 30.0724, lng: 31.0116),
            'lat' => 30.0724,
            'lng' => 31.0116,
            'district' => '6 أكتوبر',
        ]);

        return [$origin, $destination];
    }

    private function seedCorridor(Place $origin, Place $destination): Corridor
    {
        return Corridor::factory()->create([
            'name' => 'Rehab → Smart Village',
            'name_ar' => 'الرحاب ← القرية الذكية',
            'origin_place_id' => $origin->id,
            'destination_place_id' => $destination->id,
            'window_start' => '06:45:00',
            'window_end' => '08:00:00',
            'days_mask' => DaysMask::weekdaysSunToThu()->value,
            'status' => CorridorStatus::Healthy,
            'drivers_count' => 46,
            'seekers_count' => 128,
            'seat_fill_pct' => 92.0,
        ]);
    }

    private function seedNour(Organization $organization, AdminUser $admin): User
    {
        $nour = User::factory()->woman()->create([
            'full_name' => 'نور حسن محمد',
            'public_first_name' => 'نور',
            'org_type' => OrgType::Work,
            'organization_id' => $organization->id,
            'registered_role' => RegisteredRole::Driver,
            'account_status' => AccountStatus::Active,
            'profile_status' => ProfileStatus::BasicComplete,
            'trust_level' => 4,
        ]);

        foreach ([VerificationType::Phone, VerificationType::GovernmentId, VerificationType::Selfie, VerificationType::Organization] as $type) {
            UserVerification::factory()->approved()->create([
                'user_id' => $nour->id,
                'type' => $type,
                'reviewed_by' => $admin->id,
            ]);
        }

        TrustScore::factory()->create([
            'user_id' => $nour->id,
            'score' => 96,
            'public_tier' => PublicTrustTier::HighlyTrusted,
        ]);

        $driverProfile = DriverProfile::factory()->approved()->create([
            'user_id' => $nour->id,
            'reviewer_id' => $admin->id,
            'completed_trips_count' => 318,
            'cancellation_rate' => 2.10,
            'on_time_rate' => 98.00,
        ]);

        Vehicle::factory()->create([
            'driver_profile_id' => $driverProfile->user_id,
            'make' => 'Kia',
            'model' => 'Sportage',
            'year' => 2021,
            'colour' => 'فضي',
            'plate_number' => 'ق ط 421',
            'plate_normalized' => Vehicle::normalizePlate('ق ط 421'),
            'seats' => 4,
            'is_active' => true,
            'verification_status' => VehicleVerificationStatus::Approved,
        ]);

        return $nour;
    }

    private function seedMariam(Organization $organization): User
    {
        $mariam = User::factory()->woman()->create([
            'full_name' => 'مريم أحمد سيد',
            'public_first_name' => 'مريم',
            'org_type' => OrgType::Work,
            'organization_id' => $organization->id,
            'registered_role' => RegisteredRole::Passenger,
            'account_status' => AccountStatus::Active,
            'profile_status' => ProfileStatus::BasicComplete,
            'trust_level' => 2,
        ]);

        UserVerification::factory()->approved()->create([
            'user_id' => $mariam->id,
            'type' => VerificationType::GovernmentId,
        ]);

        TrustScore::factory()->create([
            'user_id' => $mariam->id,
            'score' => 78,
            'public_tier' => PublicTrustTier::Trusted,
        ]);

        return $mariam;
    }

    private function seedCommuteOffer(User $nour, Corridor $corridor, Place $origin, Place $destination): CommuteOffer
    {
        $driverProfile = $nour->driverProfile;
        $vehicle = $driverProfile->vehicles()->first();

        $offer = CommuteOffer::create([
            'driver_profile_id' => $driverProfile->user_id,
            'vehicle_id' => $vehicle->id,
            'corridor_id' => $corridor->id,
            'commute_type' => CommuteType::Recurring,
            'status' => CommuteOfferStatus::Published,
            'direction' => CommuteDirection::ToWork,
            'seats_total' => 3,
            'price_per_seat_piastres' => 8000,
            'max_detour_minutes' => 10,
            'max_walk_minutes' => 10,
            'audience' => CommuteAudience::WomenOnly,
            'allows_custom_pickup' => true,
            'route_polyline' => '}_p~iF~ps|U_ulL',
            'route_distance_meters' => 32000,
            'route_duration_seconds' => 2880,
            'bbox_min_lat' => 30.02,
            'bbox_max_lat' => 30.08,
            'bbox_min_lng' => 31.00,
            'bbox_max_lng' => 31.49,
            'published_at' => now(),
        ]);

        CommuteLocation::create([
            'commute_offer_id' => $offer->id,
            'place_id' => $origin->id,
            'type' => CommuteLocationType::Origin,
            'point' => new Coordinate(lat: $origin->lat, lng: $origin->lng),
            'lat' => $origin->lat,
            'lng' => $origin->lng,
            'address' => 'الرحاب، بوابة ٢',
            'sequence' => 0,
        ]);

        CommuteLocation::create([
            'commute_offer_id' => $offer->id,
            'place_id' => $destination->id,
            'type' => CommuteLocationType::Destination,
            'point' => new Coordinate(lat: $destination->lat, lng: $destination->lng),
            'lat' => $destination->lat,
            'lng' => $destination->lng,
            'address' => 'القرية الذكية، مبنى B6',
            'sequence' => 999,
        ]);

        return $offer;
    }

    private function seedSchedule(CommuteOffer $offer): CommuteSchedule
    {
        return CommuteSchedule::create([
            'commute_offer_id' => $offer->id,
            'days_mask' => DaysMask::weekdaysSunToThu()->value,
            'departure_time' => '07:05:00',
            'timezone' => 'Africa/Cairo',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
        ]);
    }

    private function seedRules(CommuteOffer $offer): void
    {
        foreach ([CommuteRuleKey::NonSmoking, CommuteRuleKey::Quiet, CommuteRuleKey::Ac] as $key) {
            CommuteRule::create([
                'commute_offer_id' => $offer->id,
                'rule_key' => $key,
                'rule_value' => true,
            ]);
        }
    }

    private function seedGroup(CommuteOffer $offer, User $nour): CommuteGroup
    {
        $group = CommuteGroup::create([
            'commute_offer_id' => $offer->id,
            'name' => 'الرحاب ← سمارت فيلدج · صباحًا',
            'status' => CommuteGroupStatus::Active,
            'min_commitment_days_per_week' => 3,
            'on_time_pct' => 98.00,
            'rides_together_count' => 42,
            'seats_open' => 2,
        ]);

        GroupMember::create([
            'commute_group_id' => $group->id,
            'user_id' => $nour->id,
            'role' => GroupMemberRole::Driver,
            'status' => GroupMemberStatus::Active,
            'joined_at' => now()->subMonths(3),
        ]);

        return $group;
    }

    private function seedScheduledTrip(CommuteOffer $offer, CommuteSchedule $schedule): ScheduledTrip
    {
        $tripDate = now()->next('Sunday')->toDateString();

        return ScheduledTrip::create([
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => $tripDate,
            'departure_at' => DepartureTimeCalculator::toUtc($tripDate, $schedule->departure_time, $schedule->timezone),
            'departure_local' => "{$tripDate} 07:05:00",
            'seats_total' => $offer->seats_total,
            'seats_taken' => 0,
            'price_snapshot_piastres' => $offer->price_per_seat_piastres,
            'status' => ScheduledTripStatus::Scheduled,
            'booking_deadline_at' => now()->parse($tripDate)->subDay()->setTime(21, 0),
        ]);
    }

    private function seedMariamsBooking(User $mariam, CommuteOffer $offer, ScheduledTrip $trip, CommuteGroup $group): void
    {
        $seatRequest = SeatRequest::create([
            'passenger_user_id' => $mariam->id,
            'commute_offer_id' => $offer->id,
            'scheduled_trip_id' => $trip->id,
            'commitment' => SeatRequestCommitment::Trial,
            'seats' => 1,
            'meeting_preference' => MeetingPreference::Gate,
            'intro_message' => 'أهلاً نور! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.',
            'agreed_to_rules_at' => now(),
            'payment_type' => PaymentType::Cash,
            'status' => SeatRequestStatus::Approved,
            'responded_by' => $offer->driverProfile->user_id,
            'responded_at' => now(),
        ]);

        $platformFee = (int) round($trip->price_snapshot_piastres * 0.03);

        $booking = Booking::create([
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $mariam->id,
            'driver_profile_id' => $offer->driver_profile_id,
            'commute_group_id' => $group->id,
            'seat_request_id' => $seatRequest->id,
            'seats_reserved' => 1,
            'price_snapshot_piastres' => $trip->price_snapshot_piastres,
            'platform_fee_snapshot_piastres' => $platformFee,
            'driver_amount_snapshot_piastres' => $trip->price_snapshot_piastres - $platformFee,
            'payment_type' => PaymentType::Cash,
            'payment_status' => PaymentStatus::NotDue,
            'status' => BookingStatus::Confirmed,
        ]);

        $trip->increment('seats_taken');

        GroupMember::create([
            'commute_group_id' => $group->id,
            'user_id' => $mariam->id,
            'role' => GroupMemberRole::Trial,
            'status' => GroupMemberStatus::Active,
            'joined_at' => now(),
        ]);

        Conversation::create([
            'booking_id' => $booking->id,
            'opened_at' => now(),
        ]);
    }

    /**
     * "24 في طابور التوثيق" — 24 pending government-ID verifications for the
     * admin dashboard's queue (Bible §Part 2 / Master Plan Phase 1 DoD).
     */
    private function seedVerificationQueue(AdminUser $admin): void
    {
        User::factory()->count(24)->create()->each(function (User $user) {
            UserVerification::factory()->create([
                'user_id' => $user->id,
                'type' => VerificationType::GovernmentId,
                'status' => VerificationStatus::Pending,
            ]);
        });
    }

    /** One open safety case, so the admin dashboard's Safety section isn't empty. */
    private function seedOpenSafetyCase(User $reporter, User $reported): void
    {
        Incident::factory()->create([
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'category' => IncidentCategory::UnsafeDriving,
            'severity' => SafetySeverity::High,
            'description' => 'السائقة كانت بتسرع جدًا وبتتخطى إشارات المرور.',
            'sla_due_at' => now()->addHours(24),
        ]);
    }
}
