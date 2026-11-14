{{--
    DRIVER APPLICATIONS (A01–A0x) and car papers (E). One card per applicant: identity items,
    every detail behind "show all", each car with the reviewer's answer (seats, tier, AC), then
    the decision. Below: registration renewals and cars suspended after a report.
--}}
<div>
    {{-- `total()`, not `count()` — see the note in the verification queue. --}}
    <x-admin.page-head :title="__('admin.drivers.title')" :sub="trans_choice('admin.drivers.waiting', $applications->total(), ['count' => $applications->total()])" />

    @error('queue') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    <div class="ad-stack">
        @forelse ($applications as $application)
            @php
                $identity = $application->user->verifications
                    ->filter(fn ($verification) => in_array($verification->type->value, ['government_id', 'selfie'], true))
                    ->keyBy(fn ($verification) => $verification->type->value);
                $expired = $application->licence_expiry !== null && $application->licence_expiry->isPast();
            @endphp
            <x-admin.card flush wire:key="driver-{{ $application->user_id }}">
                <div class="ad-card__body">
                    <div class="ad-row" style="gap:12px;flex-wrap:nowrap">
                        <span class="ad-avatar" aria-hidden="true">{{ mb_substr($application->user->full_name, 0, 1) }}</span>
                        <div style="flex:1;min-inline-size:0">
                            <div style="font-weight:700"><bdi>{{ $application->user->full_name }}</bdi></div>
                            <div class="ad-muted" style="font-size:12.5px">{{ __('admin.drivers.licence_until', ['date' => $application->licence_expiry?->toDateString() ?? '—']) }}</div>
                        </div>
                        @if ($expired)
                            {{-- Surfaced before the reviewer clicks, because approval will refuse it anyway. --}}
                            <span class="ad-chip ad-chip--bad">{{ __('admin.drivers.licence_expired') }}</span>
                        @endif
                    </div>

                    {{-- Identity: the two items a driver must pass, each with its documents and its own approval. --}}
                    <table class="ad-table ad-table--dense ad-table--cards" style="margin-block-start:12px">
                        <tbody>
                        @foreach (['government_id', 'selfie'] as $type)
                            @php($verification = $identity->get($type))
                            @php($status = $verification?->status->value ?? 'not_started')
                            <tr wire:key="identity-{{ $application->user_id }}-{{ $type }}">
                                <td style="font-weight:600">{{ __('admin.details.values.'.$type) }}</td>
                                <td>
                                    <span class="ad-chip ad-chip--{{ ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad'][$status] ?? 'plain' }}">{{ __('admin.details.values.'.$status) }}</span>
                                </td>
                                <td class="ad-end">
                                    <div class="ad-row" style="justify-content:flex-end;gap:6px">
                                        @if ($maySeeDocuments && $verification)
                                            @foreach ($verification->documents as $document)
                                                <a class="ad-btn ad-btn--sm" target="_blank" rel="noopener"
                                                   href="{{ route('admin.files.document', $document->id) }}"><x-admin.icon name="file" size="14" />{{ __('admin.files.open', ['what' => __('admin.files.kinds.'.$document->kind->value)]) }}</a>
                                            @endforeach
                                        @endif
                                        @if ($mayDecideIdentity && $verification?->status->value === 'pending')
                                            <button type="button" class="ad-btn ad-btn--sm ad-btn--primary"
                                                    wire:click="approveIdentity('{{ $verification->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="approveIdentity('{{ $verification->id }}')">
                                                {{ __('admin.drivers.approve_item', ['item' => __('admin.details.values.'.$type)]) }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <details wire:ignore.self class="ad-details">
                        <summary class="ad-btn ad-btn--sm">{{ __('admin.details.show_all') }}</summary>
                        <x-rq-member-details :member="$application->user" :may-see-documents="$maySeeDocuments"
                                             :revealed="$revealedId === $application->user_id" />
                    </details>

                    @foreach ($application->vehicles as $vehicle)
                        <div class="ad-subcard" wire:key="car-{{ $vehicle->id }}">
                            <div class="ad-row" style="gap:8px">
                                <x-admin.icon name="car" />
                                <strong style="flex:1"><bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi> · <bdi>{{ $vehicle->colour }}</bdi></strong>
                                <span class="ad-muted">{{ __('admin.drivers.car.entered_seats', ['count' => $vehicle->seats]) }}</span>
                            </div>

                            {{-- The car review (F2-7): the reviewer's answer, not the driver's entry. --}}
                            @if ($mayDecide)
                                <div class="ad-fields-inline">
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
                                <div class="ad-row">
                                    <button type="button" class="ad-btn ad-btn--sm ad-btn--dangerline" wire:click="openCarRejection('{{ $vehicle->id }}', 'vehicle_registration')">{{ __('admin.drivers.car.reject_registration') }}</button>
                                    <button type="button" class="ad-btn ad-btn--sm ad-btn--dangerline" wire:click="openCarRejection('{{ $vehicle->id }}', 'vehicle_details')">{{ __('admin.drivers.car.reject_details') }}</button>
                                </div>

                                @if ($carRejectionVehicleId === $vehicle->id)
                                    <form wire:submit="rejectCar('{{ $application->user_id }}')" class="ad-confirm">
                                        <p class="ad-hint" style="margin:0 0 8px">{{ __('notifications.driver_items.'.$carRejectionItem) }}</p>
                                        <div class="ad-field">
                                            <textarea class="ad-textarea" wire:model="reason" rows="3" required
                                                      aria-label="{{ __('admin.queue.reason_placeholder') }}"
                                                      placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                                            @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                            @error('carRejectionItem') <span class="ad-error">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="ad-row">
                                            <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.drivers.confirm_reject') }}</button>
                                            <button type="button" class="ad-btn" wire:click="closeCarRejection">{{ __('admin.queue.cancel') }}</button>
                                        </div>
                                    </form>
                                @endif
                            @endif
                        </div>
                    @endforeach

                    @if ($mayDecide)
                        <div class="ad-row" style="margin-block-start:14px">
                            <button type="button" class="ad-btn ad-btn--primary"
                                    wire:click="approve('{{ $application->user_id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="approve('{{ $application->user_id }}')">
                                <span wire:loading.remove wire:target="approve('{{ $application->user_id }}')">{{ __('admin.drivers.approve') }}</span>
                                <span wire:loading wire:target="approve('{{ $application->user_id }}')">{{ __('admin.queue.working') }}</span>
                            </button>
                            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="$set('openId', '{{ $application->user_id }}')">{{ __('admin.drivers.reject') }}</button>
                        </div>

                        @if ($openId === $application->user_id)
                            <form wire:submit="reject('{{ $application->user_id }}')" class="ad-confirm">
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="reason" rows="3" required
                                              aria-label="{{ __('admin.queue.reason_placeholder') }}"
                                              placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.drivers.confirm_reject') }}</button>
                                    <button type="button" class="ad-btn" wire:click="$set('openId', null)">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    @else
                        <p class="ad-hint" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
                    @endif
                </div>
            </x-admin.card>
        @empty
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.drivers.empty')" /></x-admin.card>
        @endforelse
    </div>

    <x-rq-pager :page="$applications" />

    {{-- ─────────── Car papers: renewals and cars suspended after a report ─────────── --}}
    <div class="ad-section-label">{{ __('admin.drivers.cars_review.title') }}</div>

    <div class="ad-stack">
        @forelse ($renewals as $car)
            <x-admin.card flush wire:key="renewal-{{ $car->id }}">
                <div class="ad-card__body">
                    <div style="font-weight:700"><bdi>{{ $car->driverProfile?->user?->full_name }}</bdi> · <bdi>{{ $car->make }} {{ $car->model }}</bdi> · <span class="ad-mono"><bdi>{{ $car->plate_number }}</bdi></span></div>
                    <p class="ad-hint" style="margin:4px 0 0">{{ __('admin.drivers.cars_review.renewal_waiting') }}</p>
                    @if ($mayDecide)
                        <div class="ad-fields-inline" style="margin-block-start:12px">
                            <div class="ad-field">
                                <label class="ad-label" for="renewal-date-{{ $car->id }}">{{ __('admin.drivers.cars_review.new_expiry') }}</label>
                                <input id="renewal-date-{{ $car->id }}" class="ad-input" type="date" wire:model="renewalDates.{{ $car->id }}">
                                @error("renewalDates.{$car->id}") <span class="ad-error">{{ $message }}</span> @enderror
                            </div>
                            <button class="ad-btn ad-btn--primary" type="button" style="align-self:end;margin-block-end:14px" wire:click="approveRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.approve') }}</button>
                        </div>
                        <div class="ad-fields-inline">
                            <div class="ad-field" style="flex:2">
                                <input class="ad-input" type="text" aria-label="{{ __('admin.drivers.cars_review.reject_reason') }}" placeholder="{{ __('admin.drivers.cars_review.reject_reason') }}" wire:model="renewalReasons.{{ $car->id }}">
                                @error("renewalReasons.{$car->id}") <span class="ad-error">{{ $message }}</span> @enderror
                            </div>
                            <button class="ad-btn ad-btn--dangerline" type="button" style="margin-block-end:14px" wire:click="rejectRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reject') }}</button>
                        </div>
                    @endif
                </div>
            </x-admin.card>
        @empty
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.drivers.cars_review.no_renewals')" /></x-admin.card>
        @endforelse

        @foreach ($reportedCars as $car)
            <x-admin.card flush tone="topline-bad" wire:key="reported-{{ $car->id }}">
                <div class="ad-card__body ad-row">
                    <div style="flex:1;font-weight:700"><bdi>{{ $car->driverProfile?->user?->full_name }}</bdi> · <bdi>{{ $car->make }} {{ $car->model }}</bdi> · <span class="ad-mono"><bdi>{{ $car->plate_number }}</bdi></span></div>
                    @if ($mayDecide)
                        <button class="ad-btn ad-btn--sm" type="button" wire:click="reinstate('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reinstate') }}</button>
                    @endif
                </div>
            </x-admin.card>
        @endforeach

        @if ($mayDecide)
            <form wire:submit="suspendAfterReport" class="ad-card ad-card__body">
                <p class="ad-hint" style="margin:0 0 10px">{{ __('admin.drivers.cars_review.suspend_hint') }}</p>
                <div class="ad-fields-inline">
                    <div class="ad-field">
                        <input class="ad-input" type="text" dir="auto" aria-label="{{ __('admin.drivers.cars_review.plate') }}" placeholder="{{ __('admin.drivers.cars_review.plate') }}" wire:model="suspendPlate">
                        @error('suspendPlate') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field" style="flex:2">
                        <input class="ad-input" type="text" aria-label="{{ __('admin.drivers.cars_review.report_note') }}" placeholder="{{ __('admin.drivers.cars_review.report_note') }}" wire:model="suspendNote">
                        @error('suspendNote') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <button class="ad-btn ad-btn--danger" type="submit" style="margin-block-end:14px">{{ __('admin.drivers.cars_review.suspend') }}</button>
                </div>
            </form>
        @endif
    </div>
</div>
