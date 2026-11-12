<div>
    <h1 class="rq-h1">{{ __('admin.replacement_cars.title') }}</h1>
    <p class="rq-sub">{{ trans_choice('admin.replacement_cars.waiting', $vehicles->total(), ['count' => $vehicles->total()]) }}</p>

    @forelse ($vehicles as $vehicle)
        @php($driver = $vehicle->driverProfile)
        @php($current = $driver->vehicles->first(fn ($car) => $car->is_active && $car->id !== $vehicle->id))
        <article class="rq-card" wire:key="replacement-{{ $vehicle->id }}">
            <div style="display:flex;align-items:center;gap:12px">
                <span class="rq-avatar">{{ mb_substr($driver->user->full_name, 0, 1) }}</span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">{{ $driver->user->full_name }}</div>
                    <div class="rq-note">
                        @if ($current)
                            {{ __('admin.replacement_cars.current_car', ['car' => trim("{$current->make} {$current->model}"), 'plate' => $current->plate_number]) }}
                        @else
                            {{ __('admin.replacement_cars.no_current_car') }}
                        @endif
                    </div>
                </div>
            </div>

            <ul class="rq-checks">
                <li class="rq-check">
                    <span class="rq-check__label">
                        {{ $vehicle->make }} {{ $vehicle->model }} &middot; {{ $vehicle->year }} &middot; {{ $vehicle->colour }} &middot; {{ $vehicle->plate_number }}
                    </span>
                    <span>{{ __('admin.drivers.car.entered_seats', ['count' => $vehicle->seats]) }}</span>
                </li>
                @forelse ($vehicle->documents as $document)
                    <li class="rq-check" wire:key="replacement-document-{{ $document->id }}">
                        <span class="rq-check__label">
                            {{ __('admin.details.values.'.$document->type->value) }}
                            &middot; {{ __('admin.details.expires') }}: {{ $document->expires_at?->format('j M Y') ?? '—' }}
                        </span>
                        @if ($document->purged_at)
                            <span class="rq-note">{{ __('admin.details.purged') }}</span>
                        @elseif ($maySeeDocuments)
                            <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
                               href="{{ route('admin.files.vehicle-document', $document->id) }}">{{ __('admin.files.open', ['what' => __('admin.details.values.'.$document->type->value)]) }}</a>
                        @endif
                    </li>
                @empty
                    <li class="rq-note">{{ __('admin.details.no_car_documents') }}</li>
                @endforelse

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
                @endif
            </ul>

            @error("cars.{$vehicle->id}") <p class="rq-error">{{ $message }}</p> @enderror

            @if ($mayDecide)
                <div class="rq-actions">
                    <button type="button" class="rq-btn rq-btn--approve"
                            wire:click="approve('{{ $vehicle->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="approve('{{ $vehicle->id }}')">
                        <span wire:loading.remove wire:target="approve('{{ $vehicle->id }}')">{{ __('admin.replacement_cars.approve') }}</span>
                        <span wire:loading wire:target="approve('{{ $vehicle->id }}')">{{ __('admin.queue.working') }}</span>
                    </button>
                    <button type="button" class="rq-btn rq-btn--quiet"
                            wire:click="openRejection('{{ $vehicle->id }}', 'vehicle_registration')">
                        {{ __('admin.drivers.car.reject_registration') }}
                    </button>
                    <button type="button" class="rq-btn rq-btn--quiet"
                            wire:click="openRejection('{{ $vehicle->id }}', 'vehicle_details')">
                        {{ __('admin.drivers.car.reject_details') }}
                    </button>
                </div>

                @if ($rejectingId === $vehicle->id)
                    <form wire:submit="reject" style="margin-top:13px">
                        <p class="rq-note">{{ __('notifications.driver_items.'.$rejectionItem) }}</p>
                        <textarea class="rq-field" wire:model="reason" rows="3" required
                                  placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                        @error('reason') <p class="rq-error">{{ $message }}</p> @enderror
                        @error('rejectionItem') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="submit" class="rq-btn rq-btn--danger" wire:loading.attr="disabled">
                                {{ __('admin.drivers.confirm_reject') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="closeRejection">
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
        <p class="rq-empty">{{ __('admin.replacement_cars.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$vehicles" />
</div>
