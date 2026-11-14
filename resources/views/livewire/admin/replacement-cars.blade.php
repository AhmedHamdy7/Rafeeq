{{-- REPLACEMENT CARS (E). An approved driver's new car: the car, its papers, the reviewer's answer, the decision. --}}
<div>
    <x-admin.page-head :title="__('admin.replacement_cars.title')" :sub="trans_choice('admin.replacement_cars.waiting', $vehicles->total(), ['count' => $vehicles->total()])" />

    <div class="ad-stack">
        @forelse ($vehicles as $vehicle)
            @php($driver = $vehicle->driverProfile)
            @php($current = $driver->vehicles->first(fn ($car) => $car->is_active && $car->id !== $vehicle->id))
            <x-admin.card flush wire:key="replacement-{{ $vehicle->id }}">
                <div class="ad-card__body">
                    <div class="ad-row" style="gap:12px;flex-wrap:nowrap">
                        <span class="ad-avatar" aria-hidden="true">{{ mb_substr($driver->user->full_name, 0, 1) }}</span>
                        <div style="flex:1;min-inline-size:0">
                            <div style="font-weight:700"><bdi>{{ $driver->user->full_name }}</bdi></div>
                            <div class="ad-muted" style="font-size:12.5px">
                                @if ($current)
                                    {{ __('admin.replacement_cars.current_car', ['car' => trim("{$current->make} {$current->model}"), 'plate' => $current->plate_number]) }}
                                @else
                                    {{ __('admin.replacement_cars.no_current_car') }}
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="ad-subcard">
                        <div class="ad-row" style="gap:8px">
                            <x-admin.icon name="car" />
                            <strong style="flex:1"><bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi> · {{ $vehicle->year }} · <bdi>{{ $vehicle->colour }}</bdi> · <span class="ad-mono"><bdi>{{ $vehicle->plate_number }}</bdi></span></strong>
                            <span class="ad-muted">{{ __('admin.drivers.car.entered_seats', ['count' => $vehicle->seats]) }}</span>
                        </div>

                        <table class="ad-table ad-table--dense" style="margin-block-start:8px">
                            <tbody>
                            @forelse ($vehicle->documents as $document)
                                <tr wire:key="replacement-document-{{ $document->id }}">
                                    <td>{{ __('admin.details.values.'.$document->type->value) }}</td>
                                    <td class="ad-muted">{{ __('admin.details.expires') }}: {{ $document->expires_at?->format('j M Y') ?? '—' }}</td>
                                    <td class="ad-end">
                                        @if ($document->purged_at)
                                            <span class="ad-hint">{{ __('admin.details.purged') }}</span>
                                        @elseif ($maySeeDocuments)
                                            <a class="ad-btn ad-btn--sm" target="_blank" rel="noopener"
                                               href="{{ route('admin.files.vehicle-document', $document->id) }}"><x-admin.icon name="file" size="14" />{{ __('admin.files.open', ['what' => __('admin.details.values.'.$document->type->value)]) }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="ad-hint">{{ __('admin.details.no_car_documents') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>

                        @if ($mayDecide)
                            <div class="ad-fields-inline" style="margin-block-start:10px">
                                <div class="ad-field">
                                    <label class="ad-label" for="seats-{{ $vehicle->id }}">{{ __('admin.drivers.car.approved_seats') }}</label>
                                    <input id="seats-{{ $vehicle->id }}" class="ad-input" type="number"
                                           min="{{ $minApprovedSeats }}" max="{{ $maxApprovedSeats }}"
                                           wire:model="cars.{{ $vehicle->id }}.approvedSeats">
                                </div>
                                <div class="ad-field">
                                    <label class="ad-label" for="tier-{{ $vehicle->id }}">{{ __('admin.drivers.car.tier') }}</label>
                                    <select id="tier-{{ $vehicle->id }}" class="ad-select" wire:model="cars.{{ $vehicle->id }}.tier">
                                        <option value="standard">{{ __('admin.drivers.car.tier_standard') }}</option>
                                        <option value="comfort">{{ __('admin.drivers.car.tier_comfort') }}</option>
                                    </select>
                                </div>
                                <label class="ad-check" style="align-self:end;padding-block-end:14px">
                                    <input type="checkbox" wire:model="cars.{{ $vehicle->id }}.hasAc"> {{ __('admin.drivers.car.has_ac') }}@if ($vehicle->declared_ac !== null) <span class="ad-muted">· {{ __('admin.drivers.car.'.($vehicle->declared_ac ? 'declared_ac_yes' : 'declared_ac_no')) }}</span>@endif
                                </label>
                            </div>
                        @endif
                    </div>

                    @error("cars.{$vehicle->id}") <p class="ad-error">{{ $message }}</p> @enderror

                    @if ($mayDecide)
                        <div class="ad-row" style="margin-block-start:14px">
                            <button type="button" class="ad-btn ad-btn--primary"
                                    wire:click="approve('{{ $vehicle->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="approve('{{ $vehicle->id }}')">
                                <span wire:loading.remove wire:target="approve('{{ $vehicle->id }}')">{{ __('admin.replacement_cars.approve') }}</span>
                                <span wire:loading wire:target="approve('{{ $vehicle->id }}')">{{ __('admin.queue.working') }}</span>
                            </button>
                            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="openRejection('{{ $vehicle->id }}', 'vehicle_registration')">{{ __('admin.drivers.car.reject_registration') }}</button>
                            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="openRejection('{{ $vehicle->id }}', 'vehicle_details')">{{ __('admin.drivers.car.reject_details') }}</button>
                        </div>

                        @if ($rejectingId === $vehicle->id)
                            <form wire:submit="reject" class="ad-confirm">
                                <p class="ad-hint" style="margin:0 0 8px">{{ __('notifications.driver_items.'.$rejectionItem) }}</p>
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="reason" rows="3" required
                                              aria-label="{{ __('admin.queue.reason_placeholder') }}"
                                              placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                    @error('rejectionItem') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.drivers.confirm_reject') }}</button>
                                    <button type="button" class="ad-btn" wire:click="closeRejection">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    @else
                        <p class="ad-hint" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
                    @endif
                </div>
            </x-admin.card>
        @empty
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.replacement_cars.empty')" /></x-admin.card>
        @endforelse
    </div>

    <x-rq-pager :page="$vehicles" />
</div>
