<?php

namespace App\Domains\Safety\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Contracts\ContactMessenger;
use App\Domains\Safety\Enums\AutoShareMessageStatus;
use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Safety\Models\EmergencyContact;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TrustedContactNotice
{
    public const string KIND = 'contact_added';

    public static function send(User $user, EmergencyContact $contact): AutoShareMessage
    {
        $locale = $user->preferred_language ?: config('app.locale');
        $text = __('safety.trusted_contact_added_sms', ['name' => $user->public_first_name], $locale);

        $message = new AutoShareMessage;
        $message->fill([
            'kind' => self::KIND,
            'user_id' => $user->id,
            'trip_session_id' => null,
            'emergency_contact_id' => $contact->id,
            'channel' => 'sms',
            'to_e164' => $contact->phone_e164,
        ]);

        if (SilentNumberMessenger::isSilent($contact->phone_e164)) {
            $message->channel = 'sms';
            $message->status = AutoShareMessageStatus::Sent;
            $message->sent_at = now();
            $message->save();

            return $message;
        }

        if (! AutoShare::messengerReady()) {
            $message->status = AutoShareMessageStatus::Failed;
            $message->failure_reason = 'MESSENGER_UNAVAILABLE';
            $message->save();

            return $message;
        }

        $messenger = app(ContactMessenger::class);
        $message->channel = $messenger->channel();
        $message->body = $messenger->keepsPreview() ? $text : null;

        try {
            $messenger->send($contact->phone_e164, $text);

            $message->status = AutoShareMessageStatus::Sent;
            $message->sent_at = now();
        } catch (Throwable $e) {
            Log::warning('Trusted contact notice not delivered', ['contactId' => $contact->id, 'error' => $e->getMessage()]);

            $message->status = AutoShareMessageStatus::Failed;
            $message->failure_reason = 'DELIVERY_FAILED';
        }

        $message->save();

        return $message;
    }
}
