@props(['member', 'maySeeDocuments' => false, 'revealed' => false])
@php
    $label = function ($value): string {
        if ($value === null || $value === '') {
            return '—';
        }
        $value = $value instanceof \BackedEnum ? $value->value : (string) $value;
        $key = 'admin.details.values.'.$value;

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : \Illuminate\Support\Str::headline($value);
    };
    $date = fn ($value, string $format = 'j M Y') => $value === null ? '—' : $value->timezone('Africa/Cairo')->format($format);
    $percent = fn ($value) => $value === null ? '—' : number_format((float) $value).'%';
    $profile = $member->driverProfile;
    $stats = $member->stats;
@endphp
<section class="rq-details" style="margin-top:14px">
    <h3 class="rq-label">{{ __('admin.details.personal') }}</h3>
    @if ($maySeeDocuments && $member->profile_photo_path)
        <p style="margin:0 0 10px">
            <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
               href="{{ route('admin.files.photo', $member->id) }}">{{ __('admin.files.open', ['what' => __('admin.details.photo')]) }}</a>
        </p>
    @endif
    <dl class="rq-facts">
        <div><dt>{{ __('admin.details.full_name') }}</dt><dd><bdi>{{ $member->full_name ?? '—' }}</bdi></dd></div>
        <div><dt>{{ __('admin.details.public_name') }}</dt><dd><bdi>{{ $member->public_first_name ?? '—' }}</bdi></dd></div>
        <div><dt>{{ __('admin.details.phone') }}</dt><dd class="rq-mono" dir="ltr">{{ $member->phone_e164 }}</dd></div>
        <div><dt>{{ __('admin.details.phone_verified') }}</dt><dd>{{ $date($member->phone_verified_at, 'j M Y H:i') }}</dd></div>
        <div><dt>{{ __('admin.details.email') }}</dt><dd dir="ltr">{{ $member->email ?? '—' }}</dd></div>
        <div><dt>{{ __('admin.details.email_verified') }}</dt><dd>{{ $date($member->email_verified_at, 'j M Y H:i') }}</dd></div>
        <div><dt>{{ __('admin.details.gender') }}</dt><dd>{{ $label($member->gender) }}</dd></div>
        <div>
            <dt>{{ __('admin.details.date_of_birth') }}</dt>
            <dd>{{ $member->date_of_birth?->format('j M Y') ?? '—' }}@if ($member->date_of_birth) ({{ __('admin.details.age', ['age' => $member->date_of_birth->age]) }})@endif</dd>
        </div>
        <div><dt>{{ __('admin.details.registered_role') }}</dt><dd>{{ $label($member->registered_role) }}</dd></div>
        <div><dt>{{ __('admin.details.language') }}</dt><dd>{{ $member->preferred_language === 'en' ? 'English' : 'العربية' }}</dd></div>
        <div><dt>{{ __('admin.details.account_status') }}</dt><dd>{{ $label($member->account_status) }}</dd></div>
        <div><dt>{{ __('admin.details.profile_status') }}</dt><dd>{{ $label($member->profile_status) }}</dd></div>
        <div><dt>{{ __('admin.details.org_type') }}</dt><dd>{{ $label($member->org_type) }}</dd></div>
        <div>
            <dt>{{ __('admin.details.organization') }}</dt>
            <dd><bdi>{{ $member->organization ? (app()->getLocale() === 'ar' ? $member->organization->name_ar : $member->organization->name) : '—' }}</bdi></dd>
        </div>
        <div><dt>{{ __('admin.details.trust_level') }}</dt><dd>{{ $member->trust_level }} / 4</dd></div>
        <div><dt>{{ __('admin.details.joined') }}</dt><dd>{{ $date($member->created_at, 'j M Y H:i') }}</dd></div>
    </dl>

    <h3 class="rq-label" style="margin-top:14px">{{ __('admin.details.activity') }}</h3>
    <dl class="rq-facts">
        <div><dt>{{ __('admin.details.trips_as_passenger') }}</dt><dd>{{ $stats?->completed_trips_as_passenger ?? 0 }}</dd></div>
        <div><dt>{{ __('admin.details.trips_as_driver') }}</dt><dd>{{ $stats?->completed_trips_as_driver ?? 0 }}</dd></div>
        <div><dt>{{ __('admin.details.rating_as_passenger') }}</dt><dd>{{ $stats?->avg_rating_as_passenger === null ? '—' : number_format((float) $stats->avg_rating_as_passenger, 1) }}</dd></div>
        <div><dt>{{ __('admin.details.rating_as_driver') }}</dt><dd>{{ $stats?->avg_rating_as_driver === null ? '—' : number_format((float) $stats->avg_rating_as_driver, 1) }}</dd></div>
        <div><dt>{{ __('admin.details.no_shows') }}</dt><dd>{{ $stats?->no_show_count ?? 0 }}</dd></div>
        <div><dt>{{ __('admin.details.on_time') }}</dt><dd>{{ $percent($stats?->on_time_rate) }}</dd></div>
        <div><dt>{{ __('admin.details.cancellation_rate') }}</dt><dd>{{ $percent($stats?->cancellation_rate) }}</dd></div>
    </dl>

    <h3 class="rq-label" style="margin-top:14px">{{ __('admin.details.verifications') }}</h3>
    @forelse ($member->verifications as $verification)
        <div class="rq-check" wire:key="verification-{{ $verification->id }}" style="display:block">
            <div><strong>{{ $label($verification->type) }}</strong> &middot; {{ $label($verification->status) }}</div>
            <div class="rq-note">
                {{ __('admin.details.method') }}: {{ $label($verification->method) }}
                &middot; {{ __('admin.details.attempts') }}: {{ $verification->attempt_count }}
                &middot; {{ __('admin.details.submitted') }}: {{ $date($verification->submitted_at, 'j M Y H:i') }}
                &middot; {{ __('admin.details.reviewed') }}: {{ $date($verification->reviewed_at, 'j M Y H:i') }}@if ($verification->reviewer) ({{ $verification->reviewer->name }})@endif
                &middot; {{ __('admin.details.expires') }}: {{ $date($verification->expires_at) }}
            </div>
            @if ($verification->rejection_reason)
                <div class="rq-note">{{ __('admin.details.rejection_reason') }}: {{ $verification->rejection_reason }}</div>
            @endif
            @if ($maySeeDocuments && $verification->documents->isNotEmpty())
                <div class="rq-actions" style="margin-top:6px">
                    @foreach ($verification->documents as $document)
                        <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
                           href="{{ route('admin.files.document', $document->id) }}">{{ __('admin.files.open', ['what' => __('admin.files.kinds.'.$document->kind->value)]) }}</a>
                    @endforeach
                </div>
            @elseif ($verification->documents->isNotEmpty())
                <div class="rq-note">{{ trans_choice('admin.details.documents_count', $verification->documents->count(), ['count' => $verification->documents->count()]) }}</div>
            @endif
        </div>
    @empty
        <p class="rq-note">{{ __('admin.details.no_verifications') }}</p>
    @endforelse

    @if ($profile)
        <h3 class="rq-label" style="margin-top:14px">{{ __('admin.details.driver') }}</h3>
        <dl class="rq-facts">
            <div><dt>{{ __('admin.details.driver_status') }}</dt><dd>{{ $label($profile->status) }}</dd></div>
            <div><dt>{{ __('admin.details.licence_expiry') }}</dt><dd>{{ $profile->licence_expiry?->format('j M Y') ?? '—' }}</dd></div>
            <div>
                <dt>{{ __('admin.details.licence_number') }}</dt>
                <dd class="rq-mono" dir="ltr">{{ $revealed ? ($profile->licence_number ?? '—') : ($profile->licence_number_encrypted ? '••••••' : '—') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.details.national_id') }}</dt>
                <dd class="rq-mono" dir="ltr">{{ $revealed ? ($profile->national_id ?? '—') : ($profile->national_id_encrypted ? '••••••' : '—') }}</dd>
            </div>
            <div><dt>{{ __('admin.details.verified_at') }}</dt><dd>{{ $date($profile->verified_at, 'j M Y H:i') }}@if ($profile->reviewer) ({{ $profile->reviewer->name }})@endif</dd></div>
            <div><dt>{{ __('admin.details.completed_trips') }}</dt><dd>{{ $profile->completed_trips_count }}</dd></div>
            <div><dt>{{ __('admin.details.on_time') }}</dt><dd>{{ $percent($profile->on_time_rate) }}</dd></div>
            <div><dt>{{ __('admin.details.cancellation_rate') }}</dt><dd>{{ $percent($profile->cancellation_rate) }}</dd></div>
            <div><dt>{{ __('admin.details.applied') }}</dt><dd>{{ $date($profile->created_at, 'j M Y H:i') }}</dd></div>
            <div><dt>{{ __('admin.details.updated') }}</dt><dd>{{ $date($profile->updated_at, 'j M Y H:i') }}</dd></div>
        </dl>
        @if ($profile->rejection_reason)
            <p class="rq-note">{{ __('admin.details.rejection_reason') }}: {{ $profile->rejection_reason }}</p>
        @endif
        @if ($maySeeDocuments && ! $revealed && ($profile->national_id_encrypted || $profile->licence_number_encrypted))
            <button type="button" class="rq-btn rq-btn--quiet" wire:click="revealNumbers('{{ $profile->user_id }}')">
                {{ __('admin.details.reveal_numbers') }}
            </button>
        @endif

        @foreach ($profile->vehicles as $vehicle)
            <h3 class="rq-label" style="margin-top:14px" wire:key="details-car-{{ $vehicle->id }}">
                {{ __('admin.details.car') }}: <bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi>
            </h3>
            <dl class="rq-facts">
                <div><dt>{{ __('admin.details.plate') }}</dt><dd class="rq-mono"><bdi>{{ $vehicle->plate_number }}</bdi></dd></div>
                <div><dt>{{ __('admin.details.year') }}</dt><dd>{{ $vehicle->year }}</dd></div>
                <div><dt>{{ __('admin.details.colour') }}</dt><dd><bdi>{{ $vehicle->colour }}</bdi></dd></div>
                <div><dt>{{ __('admin.details.seats') }}</dt><dd>{{ $vehicle->seats }}</dd></div>
                <div><dt>{{ __('admin.details.approved_seats') }}</dt><dd>{{ $vehicle->approved_seats ?? '—' }}</dd></div>
                <div><dt>{{ __('admin.details.tier') }}</dt><dd>{{ $label($vehicle->tier) }}</dd></div>
                <div><dt>{{ __('admin.details.has_ac') }}</dt><dd>{{ $vehicle->has_ac === null ? '—' : ($vehicle->has_ac ? __('admin.details.yes') : __('admin.details.no')) }}</dd></div>
                <div><dt>{{ __('admin.details.transmission') }}</dt><dd>{{ $label($vehicle->transmission) }}</dd></div>
                <div><dt>{{ __('admin.details.fuel') }}</dt><dd>{{ $label($vehicle->fuel_type) }}</dd></div>
                <div><dt>{{ __('admin.details.car_status') }}</dt><dd>{{ $label($vehicle->verification_status) }}</dd></div>
                <div><dt>{{ __('admin.details.active_car') }}</dt><dd>{{ $vehicle->is_active ? __('admin.details.yes') : __('admin.details.no') }}</dd></div>
                <div><dt>{{ __('admin.details.suspension_reason') }}</dt><dd>{{ $label($vehicle->suspension_reason) }}</dd></div>
            </dl>
            @if ($vehicle->rejection_reason)
                <p class="rq-note">{{ __('admin.details.rejection_reason') }} ({{ $label($vehicle->rejected_item) }}): {{ $vehicle->rejection_reason }}</p>
            @endif
            <ul class="rq-checks">
                @forelse ($vehicle->documents as $document)
                    <li class="rq-check" wire:key="car-document-{{ $document->id }}">
                        <span class="rq-check__label">
                            {{ $label($document->type) }}
                            &middot; {{ $label($document->verification_status) }}
                            &middot; {{ __('admin.details.expires') }}: {{ $document->expires_at?->format('j M Y') ?? '—' }}
                        </span>
                        @if ($document->purged_at)
                            <span class="rq-note">{{ __('admin.details.purged') }}</span>
                        @elseif ($maySeeDocuments)
                            <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
                               href="{{ route('admin.files.vehicle-document', $document->id) }}">{{ __('admin.files.open', ['what' => $label($document->type)]) }}</a>
                        @endif
                    </li>
                @empty
                    <li class="rq-note">{{ __('admin.details.no_car_documents') }}</li>
                @endforelse
            </ul>
        @endforeach
    @endif
</section>
