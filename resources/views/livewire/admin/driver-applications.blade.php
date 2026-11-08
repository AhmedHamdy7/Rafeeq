<div>
    <h1 class="rq-h1">{{ __('admin.drivers.title') }}</h1>
    {{-- `total()`, not `count()` — see the note in the verification queue. --}}
    <p class="rq-sub">{{ trans_choice('admin.drivers.waiting', $applications->total(), ['count' => $applications->total()]) }}</p>

    @error('queue') <p class="rq-error" style="margin-bottom:14px">{{ $message }}</p> @enderror

    @forelse ($applications as $application)
        <article class="rq-card" wire:key="driver-{{ $application->user_id }}">
            <div style="display:flex;align-items:center;gap:12px">
                <span class="rq-avatar">{{ mb_substr($application->user->full_name, 0, 1) }}</span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">{{ $application->user->full_name }}</div>
                    <div class="rq-note">
                        {{ __('admin.drivers.licence_until', ['date' => $application->licence_expiry?->toDateString() ?? '—']) }}
                    </div>
                </div>
                @if ($application->licence_expiry !== null && $application->licence_expiry->isPast())
                    {{-- Surfaced before the reviewer clicks, because approval will refuse it anyway. --}}
                    <span class="rq-pill rq-pill--bad">{{ __('admin.drivers.licence_expired') }}</span>
                @endif
            </div>

            @php
                $identity = $application->user->verifications
                    ->filter(fn ($verification) => in_array($verification->type->value, ['government_id', 'selfie'], true))
                    ->keyBy(fn ($verification) => $verification->type->value);
            @endphp
            <ul class="rq-checks">
                @foreach (['government_id', 'selfie'] as $type)
                    @php($verification = $identity->get($type))
                    <li class="rq-check" wire:key="identity-{{ $application->user_id }}-{{ $type }}">
                        <span class="rq-check__label">
                            {{ __('admin.details.values.'.$type) }}
                            &middot; {{ __('admin.details.values.'.($verification?->status->value ?? 'not_started')) }}
                        </span>
                        <span class="rq-actions" style="margin:0">
                            @if ($maySeeDocuments && $verification)
                                @foreach ($verification->documents as $document)
                                    <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
                                       href="{{ route('admin.files.document', $document->id) }}">{{ __('admin.files.open', ['what' => __('admin.files.kinds.'.$document->kind->value)]) }}</a>
                                @endforeach
                            @endif
                            @if ($mayDecideIdentity && $verification?->status->value === 'pending')
                                <button type="button" class="rq-btn rq-btn--approve"
                                        wire:click="approveIdentity('{{ $verification->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="approveIdentity('{{ $verification->id }}')">
                                    {{ __('admin.drivers.approve_item', ['item' => __('admin.details.values.'.$type)]) }}
                                </button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            <details wire:ignore.self style="margin-top:10px">
                <summary class="rq-btn rq-btn--quiet">{{ __('admin.details.show_all') }}</summary>
                <x-rq-member-details :member="$application->user" :may-see-documents="$maySeeDocuments"
                                     :revealed="$revealedId === $application->user_id" />
            </details>

            <ul class="rq-checks">
                @foreach ($application->vehicles as $vehicle)
                    <li class="rq-check" wire:key="car-{{ $vehicle->id }}">
                        <span class="rq-check__label">{{ $vehicle->make }} {{ $vehicle->model }} &middot; {{ $vehicle->colour }}</span>
                        <span>{{ __('admin.drivers.car.entered_seats', ['count' => $vehicle->seats]) }}</span>
                    </li>
                    {{-- The car review (F2-7): the reviewer's answer, not the driver's entry. --}}
                    @if ($mayDecide)
                        <li class="rq-check">
                            <label class="rq-check__label" for="seats-{{ $vehicle->id }}">{{ __('admin.drivers.car.approved_seats') }}</label>
                            <input id="seats-{{ $vehicle->id }}" class="rq-field" type="number"
                                   min="{{ $minApprovedSeats }}" max="{{ $maxApprovedSeats }}" style="max-width:90px"
                                   wire:model="cars.{{ $vehicle->id }}.approvedSeats">
                        </li>
                        <li class="rq-check">
                            <label class="rq-check__label" for="tier-{{ $vehicle->id }}">{{ __('admin.drivers.car.tier') }}</label>
                            <select id="tier-{{ $vehicle->id }}" class="rq-field" style="max-width:180px"
                                    wire:model="cars.{{ $vehicle->id }}.tier">
                                <option value="standard">{{ __('admin.drivers.car.tier_standard') }}</option>
                                <option value="comfort">{{ __('admin.drivers.car.tier_comfort') }}</option>
                            </select>
                        </li>
                        <li class="rq-check">
                            <label class="rq-check__label">
                                <input type="checkbox" wire:model="cars.{{ $vehicle->id }}.hasAc">
                                {{ __('admin.drivers.car.has_ac') }}
                            </label>
                        </li>
                        <li class="rq-check">
                            <button type="button" class="rq-btn rq-btn--quiet"
                                    wire:click="openCarRejection('{{ $vehicle->id }}', 'vehicle_registration')">
                                {{ __('admin.drivers.car.reject_registration') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet"
                                    wire:click="openCarRejection('{{ $vehicle->id }}', 'vehicle_details')">
                                {{ __('admin.drivers.car.reject_details') }}
                            </button>
                        </li>
                        @if ($carRejectionVehicleId === $vehicle->id)
                            <li>
                                <form wire:submit="rejectCar('{{ $application->user_id }}')" style="margin-top:13px">
                                    <p class="rq-note">{{ __('notifications.driver_items.'.$carRejectionItem) }}</p>
                                    <textarea class="rq-field" wire:model="reason" rows="3" required
                                              placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                                    @error('reason') <p class="rq-error">{{ $message }}</p> @enderror
                                    @error('carRejectionItem') <p class="rq-error">{{ $message }}</p> @enderror
                                    <div class="rq-actions">
                                        <button type="submit" class="rq-btn rq-btn--danger" wire:loading.attr="disabled">
                                            {{ __('admin.drivers.confirm_reject') }}
                                        </button>
                                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="closeCarRejection">
                                            {{ __('admin.queue.cancel') }}
                                        </button>
                                    </div>
                                </form>
                            </li>
                        @endif
                    @endif
                @endforeach
            </ul>

            @if ($mayDecide)
                <div class="rq-actions">
                    <button type="button" class="rq-btn rq-btn--approve"
                            wire:click="approve('{{ $application->user_id }}')"
                            wire:loading.attr="disabled"
                            wire:target="approve('{{ $application->user_id }}')">
                        <span wire:loading.remove wire:target="approve('{{ $application->user_id }}')">{{ __('admin.drivers.approve') }}</span>
                        <span wire:loading wire:target="approve('{{ $application->user_id }}')">{{ __('admin.queue.working') }}</span>
                    </button>
                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openId', '{{ $application->user_id }}')">
                        {{ __('admin.drivers.reject') }}
                    </button>
                </div>

                @if ($openId === $application->user_id)
                    <form wire:submit="reject('{{ $application->user_id }}')" style="margin-top:13px">
                        <textarea class="rq-field" wire:model="reason" rows="3" required
                                  placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                        @error('reason') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="submit" class="rq-btn rq-btn--danger" wire:loading.attr="disabled">
                                {{ __('admin.drivers.confirm_reject') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openId', null)">
                                {{ __('admin.queue.cancel') }}
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <p class="rq-note" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.drivers.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$applications" />

    <h2 class="rq-h1" style="margin-top:24px">{{ __('admin.drivers.cars_review.title') }}</h2>

    @forelse ($renewals as $car)
        <article class="rq-card" wire:key="renewal-{{ $car->id }}">
            <div style="font-weight:600">{{ $car->driverProfile?->user?->full_name }} &middot; {{ $car->make }} {{ $car->model }} &middot; {{ $car->plate_number }}</div>
            <p class="rq-note">{{ __('admin.drivers.cars_review.renewal_waiting') }}</p>
            @if ($mayDecide)
                <label class="rq-check__label" for="renewal-date-{{ $car->id }}">{{ __('admin.drivers.cars_review.new_expiry') }}</label>
                <input id="renewal-date-{{ $car->id }}" class="rq-field" type="date" wire:model="renewalDates.{{ $car->id }}">
                @error("renewalDates.{$car->id}") <p class="rq-error">{{ $message }}</p> @enderror
                <button class="rq-btn" type="button" wire:click="approveRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.approve') }}</button>
                <input class="rq-field" type="text" placeholder="{{ __('admin.drivers.cars_review.reject_reason') }}" wire:model="renewalReasons.{{ $car->id }}">
                @error("renewalReasons.{$car->id}") <p class="rq-error">{{ $message }}</p> @enderror
                <button class="rq-btn" type="button" wire:click="rejectRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reject') }}</button>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.drivers.cars_review.no_renewals') }}</p>
    @endforelse

    @foreach ($reportedCars as $car)
        <article class="rq-card" wire:key="reported-{{ $car->id }}">
            <div style="font-weight:600">{{ $car->driverProfile?->user?->full_name }} &middot; {{ $car->make }} {{ $car->model }} &middot; {{ $car->plate_number }}</div>
            @if ($mayDecide)
                <button class="rq-btn" type="button" wire:click="reinstate('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reinstate') }}</button>
            @endif
        </article>
    @endforeach

    @if ($mayDecide)
        <form wire:submit="suspendAfterReport" class="rq-card">
            <p class="rq-note">{{ __('admin.drivers.cars_review.suspend_hint') }}</p>
            <input class="rq-field" type="text" placeholder="{{ __('admin.drivers.cars_review.plate') }}" wire:model="suspendPlate">
            @error('suspendPlate') <p class="rq-error">{{ $message }}</p> @enderror
            <input class="rq-field" type="text" placeholder="{{ __('admin.drivers.cars_review.report_note') }}" wire:model="suspendNote">
            @error('suspendNote') <p class="rq-error">{{ $message }}</p> @enderror
            <button class="rq-btn" type="submit">{{ __('admin.drivers.cars_review.suspend') }}</button>
        </form>
    @endif
</div>
