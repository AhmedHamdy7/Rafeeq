{{--
    DRIVERS (A01–A0x) and car papers (E). Three queues as tabs: applications, registration
    renewals, cars suspended after a report.

    An application is one card: who it is and whether it is ready at the top; identity (ID and
    selfie, each with its documents and its own approval) beside the cars (each with the
    reviewer's answer: seats, tier, AC); every detail behind "show all"; the decision at the foot.
    Only @php(...) one-liners in here: a block @php after a one-liner swallows the template.
--}}
<div>
    {{-- `total()`, not `count()` — see the note in the verification queue. --}}
    <x-admin.page-head :title="__('admin.drivers.title')" :sub="trans_choice('admin.drivers.waiting', $applications->total(), ['count' => $applications->total()])" />

    @error('queue') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    @php($tabCounts = ['applications' => $applications->total(), 'renewals' => $renewals->count(), 'reported' => $reportedCars->count()])
    <div class="ad-tabs" role="tablist">
        @foreach (['applications' => 'id', 'renewals' => 'calendar', 'reported' => 'alert'] as $name => $icon)
            <button type="button" role="tab" class="ad-tab" wire:click="$set('tab', '{{ $name }}')" wire:key="tab-{{ $name }}"
                    aria-selected="{{ $activeTab === $name ? 'true' : 'false' }}">
                <x-admin.icon :name="$icon" size="15" />
                {{ __('admin.drivers.tabs.'.$name) }}
                <span @class(['ad-drv-tabcount', 'is-hot' => $tabCounts[$name] > 0 && $name !== 'reported', 'is-bad' => $tabCounts[$name] > 0 && $name === 'reported'])>{{ $tabCounts[$name] }}</span>
            </button>
        @endforeach
    </div>

    @if ($activeTab === 'applications')
        <div class="ad-stack">
            @forelse ($applications as $application)
                @php($identity = $application->user->verifications->filter(fn ($verification) => in_array($verification->type->value, ['government_id', 'selfie'], true))->keyBy(fn ($verification) => $verification->type->value))
                @php($expired = $application->licence_expiry !== null && $application->licence_expiry->isPast())
                @php($missing = collect(['government_id', 'selfie'])->reject(fn ($type) => $identity->get($type)?->status->value === 'approved')->map(fn ($type) => __('admin.details.values.'.$type)))
                @php($blockers = $expired ? $missing->push(__('admin.drivers.licence_expired')) : $missing)

                <article class="ad-drv" wire:key="driver-{{ $application->user_id }}">
                    <header class="ad-drv__head">
                        <span class="ad-avatar ad-drv__avatar" aria-hidden="true">{{ mb_substr($application->user->full_name, 0, 1) }}</span>
                        <div class="ad-drv__who">
                            <div class="ad-drv__name"><bdi>{{ $application->user->full_name }}</bdi></div>
                            <div class="ad-drv__meta">
                                <span>{{ __('admin.drivers.waiting_since', ['time' => $application->updated_at?->diffForHumans() ?? '—']) }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ __('admin.drivers.licence_until', ['date' => $application->licence_expiry?->toDateString() ?? '—']) }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ trans_choice('admin.drivers.cars_count', $application->vehicles->count(), ['count' => $application->vehicles->count()]) }}</span>
                            </div>
                        </div>
                        <div class="ad-drv__status">
                            @if ($expired)
                                {{-- Surfaced before the reviewer clicks, because approval will refuse it anyway. --}}
                                <span class="ad-chip ad-chip--bad">{{ __('admin.drivers.licence_expired') }}</span>
                            @endif
                            @if ($blockers->isEmpty())
                                <span class="ad-chip ad-chip--ok">{{ __('admin.drivers.ready') }}</span>
                            @else
                                <span class="ad-chip ad-chip--warn">{{ __('admin.drivers.waiting_on', ['items' => $blockers->implode(app()->getLocale() === 'ar' ? '، ' : ', ')]) }}</span>
                            @endif
                        </div>
                    </header>

                    <div class="ad-drv__grid">
                        {{-- Identity: the two items a driver must pass, each with its documents and its own approval. --}}
                        <section class="ad-drv__panel">
                            <h3 class="ad-drv__panel-title"><x-admin.icon name="id" size="15" />{{ __('admin.drivers.step_identity') }}</h3>
                            <ul class="ad-drv__items">
                                @foreach (['government_id', 'selfie'] as $type)
                                    @php($verification = $identity->get($type))
                                    @php($status = $verification?->status->value ?? 'not_started')
                                    <li class="ad-drv__item" wire:key="identity-{{ $application->user_id }}-{{ $type }}">
                                        <span @class(['ad-drv__mark', 'is-'.$status]) aria-hidden="true">
                                            @if ($status === 'approved')<x-admin.icon name="check" size="13" />@elseif ($status === 'rejected')<x-admin.icon name="x" size="13" />@endif
                                        </span>
                                        <div class="ad-drv__item-body">
                                            <div class="ad-drv__item-name">{{ __('admin.details.values.'.$type) }}</div>
                                            <span class="ad-chip ad-chip--{{ ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad'][$status] ?? 'plain' }}">{{ __('admin.details.values.'.$status) }}</span>
                                        </div>
                                        <div class="ad-drv__item-actions">
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
                                    </li>
                                @endforeach
                            </ul>
                        </section>

                        <section class="ad-drv__panel">
                            <h3 class="ad-drv__panel-title"><x-admin.icon name="car" size="15" />{{ __('admin.drivers.step_cars') }}</h3>
                            @foreach ($application->vehicles as $vehicle)
                                <div class="ad-drv__car" wire:key="car-{{ $vehicle->id }}">
                                    <div class="ad-drv__car-head">
                                        <strong><bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi></strong>
                                        <span class="ad-muted"><bdi>{{ $vehicle->colour }}</bdi></span>
                                        @if ($vehicle->plate_number)
                                            <span class="ad-drv__plate" dir="auto"><bdi>{{ $vehicle->plate_number }}</bdi></span>
                                        @endif
                                    </div>
                                    <div class="ad-hint">{{ __('admin.drivers.car.entered_seats', ['count' => $vehicle->seats]) }}</div>

                                    {{-- The car review (F2-7): the reviewer's answer, not the driver's entry. --}}
                                    @if ($mayDecide)
                                        <div class="ad-drv__car-fields">
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
                                        </div>
                                        <label class="ad-check">
                                            <input type="checkbox" wire:model="cars.{{ $vehicle->id }}.hasAc"> {{ __('admin.drivers.car.has_ac') }}@if ($vehicle->declared_ac !== null) <span class="ad-muted">· {{ __('admin.drivers.car.'.($vehicle->declared_ac ? 'declared_ac_yes' : 'declared_ac_no')) }}</span>@endif
                                        </label>
                                        <div class="ad-drv__car-actions">
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
                        </section>
                    </div>

                    <details wire:ignore.self class="ad-details ad-drv__details">
                        <summary class="ad-btn ad-btn--sm ad-btn--quiet">{{ __('admin.details.show_all') }}</summary>
                        <x-rq-member-details :member="$application->user" :may-see-documents="$maySeeDocuments"
                                             :revealed="$revealedId === $application->user_id" />
                    </details>

                    <footer class="ad-drv__foot">
                        @if ($mayDecide)
                            <div class="ad-drv__foot-actions">
                                <button type="button" class="ad-btn ad-btn--primary"
                                        wire:click="approve('{{ $application->user_id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="approve('{{ $application->user_id }}')">
                                    <x-admin.icon name="check" size="15" />
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
                            <p class="ad-hint" style="margin:0">{{ __('admin.queue.view_only') }}</p>
                        @endif
                    </footer>
                </article>
            @empty
                <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.drivers.empty')" /></x-admin.card>
            @endforelse
        </div>

        <x-rq-pager :page="$applications" />
    @endif

    {{-- ─────────── Registration renewals ─────────── --}}
    @if ($activeTab === 'renewals')
        @if ($renewals->isEmpty())
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.drivers.cars_review.no_renewals')" /></x-admin.card>
        @else
            <div class="ad-drv-cards">
                @foreach ($renewals as $car)
                    <article class="ad-drv ad-drv--compact" wire:key="renewal-{{ $car->id }}">
                        <header class="ad-drv__head">
                            <span class="ad-avatar" aria-hidden="true"><x-admin.icon name="car" size="16" /></span>
                            <div class="ad-drv__who">
                                <div class="ad-drv__name"><bdi>{{ $car->make }} {{ $car->model }}</bdi></div>
                                <div class="ad-drv__meta">
                                    <bdi>{{ $car->driverProfile?->user?->full_name }}</bdi>
                                    <span class="ad-drv__plate" dir="auto"><bdi>{{ $car->plate_number }}</bdi></span>
                                </div>
                            </div>
                        </header>
                        <p class="ad-hint" style="margin:0">{{ __('admin.drivers.cars_review.renewal_waiting') }}</p>
                        @if ($mayDecide)
                            <div class="ad-drv__renew">
                                <div class="ad-field">
                                    <label class="ad-label" for="renewal-date-{{ $car->id }}">{{ __('admin.drivers.cars_review.new_expiry') }}</label>
                                    <input id="renewal-date-{{ $car->id }}" class="ad-input" type="date" wire:model="renewalDates.{{ $car->id }}">
                                    @error("renewalDates.{$car->id}") <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <button class="ad-btn ad-btn--primary" type="button" wire:click="approveRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.approve') }}</button>
                            </div>
                            <div class="ad-drv__renew">
                                <div class="ad-field">
                                    <input class="ad-input" type="text" aria-label="{{ __('admin.drivers.cars_review.reject_reason') }}" placeholder="{{ __('admin.drivers.cars_review.reject_reason') }}" wire:model="renewalReasons.{{ $car->id }}">
                                    @error("renewalReasons.{$car->id}") <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <button class="ad-btn ad-btn--dangerline" type="button" wire:click="rejectRenewal('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reject') }}</button>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ─────────── Cars suspended after a report ─────────── --}}
    @if ($activeTab === 'reported')
        <div class="ad-stack">
            @if ($reportedCars->isEmpty())
                <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.drivers.no_reported')" /></x-admin.card>
            @else
                <x-admin.card flush>
                    <ul class="ad-drv-list">
                        @foreach ($reportedCars as $car)
                            <li wire:key="reported-{{ $car->id }}">
                                <span class="ad-avatar ad-drv__avatar--bad" aria-hidden="true"><x-admin.icon name="car" size="16" /></span>
                                <div class="ad-drv__who">
                                    <div class="ad-drv__name"><bdi>{{ $car->make }} {{ $car->model }}</bdi></div>
                                    <div class="ad-drv__meta"><bdi>{{ $car->driverProfile?->user?->full_name }}</bdi> <span class="ad-drv__plate" dir="auto"><bdi>{{ $car->plate_number }}</bdi></span></div>
                                </div>
                                @if ($mayDecide)
                                    <button class="ad-btn ad-btn--sm" type="button" wire:click="reinstate('{{ $car->id }}')">{{ __('admin.drivers.cars_review.reinstate') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif

            @if ($mayDecide)
                <x-admin.card :title="__('admin.drivers.suspend_title')" bar>
                    <form wire:submit="suspendAfterReport">
                        <p class="ad-hint" style="margin:0 0 12px">{{ __('admin.drivers.cars_review.suspend_hint') }}</p>
                        <div class="ad-drv__renew">
                            <div class="ad-field" style="flex:1">
                                <input class="ad-input" type="text" dir="auto" aria-label="{{ __('admin.drivers.cars_review.plate') }}" placeholder="{{ __('admin.drivers.cars_review.plate') }}" wire:model="suspendPlate">
                                @error('suspendPlate') <span class="ad-error">{{ $message }}</span> @enderror
                            </div>
                            <div class="ad-field" style="flex:2">
                                <input class="ad-input" type="text" aria-label="{{ __('admin.drivers.cars_review.report_note') }}" placeholder="{{ __('admin.drivers.cars_review.report_note') }}" wire:model="suspendNote">
                                @error('suspendNote') <span class="ad-error">{{ $message }}</span> @enderror
                            </div>
                            <button class="ad-btn ad-btn--danger" type="submit">{{ __('admin.drivers.cars_review.suspend') }}</button>
                        </div>
                    </form>
                </x-admin.card>
            @endif
        </div>
    @endif
</div>
