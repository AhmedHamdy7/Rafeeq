<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Safety\Contracts\ContactMessenger;
use App\Domains\Safety\Enums\AutoShareMessageStatus;
use App\Domains\Safety\Enums\LiveShareSource;
use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Safety\Support\AutoShare;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AutoShareTripAction
{
    public function __construct(private ShareLiveTripAction $shares) {}

    public function forRun(TripSession $session): void
    {
        if (! AutoShare::available() || ! $session->current_status->isUnderway()) {
            return;
        }

        foreach ($this->peopleOn($session) as $user) {
            $this->forPerson($user, $session);
        }
    }

    public function forPerson(User $user, TripSession $session): void
    {
        if (! $user->auto_share_trips || ! AutoShare::available()) {
            return;
        }

        $alreadyShared = LiveShare::query()
            ->where('trip_session_id', $session->id)
            ->where('user_id', $user->id)
            ->where('source', LiveShareSource::Auto->value)
            ->exists();

        if ($alreadyShared) {
            return;
        }

        $contacts = EmergencyContact::query()
            ->where('user_id', $user->id)
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        if ($contacts->isEmpty()) {
            return;
        }

        $messenger = app(ContactMessenger::class);
        $locale = $user->preferred_language ?: config('app.locale');
        $sent = [];
        $failed = [];

        foreach ($contacts as $contact) {
            $result = $this->shares->createAutomatic($user, $session, $contact);
            $url = url('/s/'.$result['token']);
            $text = __('safety.auto_share_sms', ['name' => $user->public_first_name, 'url' => $url], $locale);

            $message = new AutoShareMessage;
            $message->fill([
                'user_id' => $user->id,
                'trip_session_id' => $session->id,
                'live_share_id' => $result['share']->id,
                'emergency_contact_id' => $contact->id,
                'channel' => $messenger->channel(),
                'to_e164' => $contact->phone_e164,
                'preview_url' => $messenger->keepsPreview() ? $url : null,
            ]);

            try {
                $messenger->send($contact->phone_e164, $text);

                $message->status = AutoShareMessageStatus::Sent;
                $message->sent_at = now();
                $sent[] = $contact->id;
            } catch (Throwable $e) {
                Log::warning('Auto-share message not delivered', ['liveShareId' => $result['share']->id, 'error' => $e->getMessage()]);

                $result['share']->forceFill(['revoked_at' => now()])->save();

                $message->status = AutoShareMessageStatus::Failed;
                $message->failure_reason = 'DELIVERY_FAILED';
                $failed[] = $contact->id;
            }

            $message->save();
        }

        $tripId = $session->scheduled_trip_id;

        if ($sent !== []) {
            Notifier::send($user, NotificationType::LiveTripAutoShared, ['count' => count($sent)], [
                'tripId' => $tripId,
                'tripSessionId' => $session->id,
                'sentTo' => implode(',', $sent),
                'failedTo' => implode(',', $failed),
            ]);

            return;
        }

        Notifier::send($user, NotificationType::LiveTripAutoShareFailed, [], [
            'tripId' => $tripId,
            'tripSessionId' => $session->id,
            'failedTo' => implode(',', $failed),
        ]);
    }

    /**
     * @return list<User>
     */
    private function peopleOn(TripSession $session): array
    {
        $session->loadMissing('scheduledTrip.commuteOffer.driverProfile.user');

        $people = [];
        $driver = $session->scheduledTrip->commuteOffer->driverProfile->user;

        if ($driver !== null) {
            $people[$driver->id] = $driver;
        }

        Booking::query()
            ->where('scheduled_trip_id', $session->scheduled_trip_id)
            ->where('status', BookingStatus::Confirmed)
            ->with('passenger')
            ->get()
            ->each(function (Booking $booking) use (&$people): void {
                if ($booking->passenger !== null) {
                    $people[$booking->passenger->id] = $booking->passenger;
                }
            });

        return array_values($people);
    }
}
