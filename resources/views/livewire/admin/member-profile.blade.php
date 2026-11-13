{{-- USER DETAIL (D01–D10, D15). Header · banners · tabs. Names members typed are in <bdi>. --}}
@php
    use App\Domains\Shared\ValueObjects\PhoneNumber;
    use Illuminate\Support\Str;

    $egp = fn (int $piastres) => 'EGP '.number_format($piastres / 100, 2);
    $isDriver = $user->driverProfile?->status?->value === 'approved';
    $suspension = $user->activeSuspension;
    $driverStanding = $standings['driver'] ?? null;
    $passengerStanding = $standings['passenger'] ?? null;
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px" aria-label="{{ __('admin/profile.breadcrumb') }}">
        <a class="ad-link" href="{{ route('admin.members') }}" wire:navigate>{{ __('admin/shell.nav.users') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <bdi>{{ $user->full_name ?? '—' }}</bdi>
    </nav>

    {{-- Header --}}
    <x-admin.card flush style="margin-block-end:12px">
        <div class="ad-card__body ad-row" style="gap:14px;align-items:flex-start">
            <span class="ad-avatar" style="inline-size:56px;block-size:56px;font-size:20px" aria-hidden="true">{{ mb_substr($user->full_name ?? '?', 0, 1) }}</span>
            <div style="flex:1;min-inline-size:220px">
                <h1 class="ad-page-title" style="margin:0"><bdi>{{ $user->full_name ?? '—' }}</bdi></h1>
                <div class="ad-row" style="gap:6px;margin-block:6px">
                    @if ($user->account_status->value === 'suspended')
                        <span class="ad-chip ad-chip--bad">{{ ($suspension?->isOverdue() ?? false) ? __('admin.members.review_overdue') : __('admin.members.on_hold') }}</span>
                    @else
                        <span class="ad-chip ad-chip--ok">{{ __('admin.members.active') }}</span>
                    @endif
                    <span class="ad-chip ad-chip--plain">{{ $isDriver ? __('admin.members.role_driver') : __('admin.members.role_rider') }}</span>
                    @if ($driverStanding?->isBlocked())
                        <span class="ad-chip ad-chip--warn">{{ __('admin/profile.unpaid_blocked') }}</span>
                    @endif
                    @if ($suspension?->is_permanent)
                        <span class="ad-chip ad-chip--bad ad-chip--plain">{{ __('admin.members.permanent') }}</span>
                    @endif
                </div>
                <div class="ad-muted" style="font-size:12.5px">
                    {{ __('admin/profile.joined', ['date' => $user->created_at->timezone('Africa/Cairo')->format('j M Y')]) }}
                    · {{ __('admin.members.trust_level') }} {{ $user->trust_level }} / 4
                    @if ($user->stats?->avg_rating_as_driver ?? $user->stats?->avg_rating_as_passenger)
                        · ★ {{ number_format((float) ($isDriver ? $user->stats?->avg_rating_as_driver : $user->stats?->avg_rating_as_passenger), 1) }}
                    @endif
                    · {{ $user->preferred_language === 'en' ? 'English' : 'العربية' }}
                </div>
            </div>
            <div class="ad-row" style="gap:6px">
                <a class="ad-btn" href="#notes" wire:click="pick('notes')"><x-admin.icon name="file" />{{ __('admin/profile.add_note') }}</a>
                @if ($mayDecide)
                    <a class="ad-btn ad-btn--dangerline" href="{{ route('admin.members', ['member' => $user->id]) }}" wire:navigate>{{ __('admin/profile.decisions') }}</a>
                @endif
            </div>
        </div>
    </x-admin.card>

    {{-- Banners: money owed and disputes, the two things that change what staff say first. --}}
    @if ($driverStanding && $driverStanding->owedPiastres() > 0)
        <div class="ad-notice ad-notice--warn" style="margin-block-end:10px">
            <x-admin.icon name="alert" />
            <span>
                <strong>{{ __('admin/profile.owes', ['amount' => $egp($driverStanding->owedPiastres())]) }}</strong>
                @if ($driverStanding->isBlocked()) · {{ __('admin/profile.blocked_note', ['due' => $driverStanding->dueAt()->format('D j M')]) }} @endif
            </span>
        </div>
    @endif
    @if ($openDisputes > 0)
        <div class="ad-notice" style="margin-block-end:10px">
            <x-admin.icon name="info" />
            <span>{{ trans_choice('admin/profile.open_disputes', $openDisputes, ['count' => $openDisputes]) }}</span>
        </div>
    @endif

    <div class="ad-tabs" role="tablist">
        @foreach ($tabs as $name)
            <button type="button" role="tab" class="ad-tab" aria-selected="{{ $tab === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">{{ __('admin/profile.tabs.'.$name) }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="ad-split">
            <x-admin.card :title="__('admin/profile.profile')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin.details.full_name') }}</dt><dd><bdi>{{ $user->full_name ?? '—' }}</bdi></dd></div>
                    <div>
                        <dt>{{ __('admin.details.phone') }}</dt>
                        <dd>
                            <span class="ad-mono" dir="ltr">{{ $revealedPhone ?? PhoneNumber::fromRaw($user->phone_e164)->masked() }}</span>
                            @if ($revealedPhone === null && ! $revealing)
                                <button type="button" class="ad-btn ad-btn--xs" wire:click="$set('revealing', true)"><x-admin.icon name="search" size="12" />{{ __('admin/profile.reveal') }}</button>
                            @endif
                        </dd>
                    </div>
                    <div><dt>{{ __('admin.details.email') }}</dt><dd dir="ltr" style="text-align:start">{{ $user->email ?? '—' }}</dd></div>
                    <div><dt>{{ __('admin.details.gender') }}</dt>@php($gender = $user->gender instanceof \BackedEnum ? $user->gender->value : $user->gender)
                    <dd>{{ $gender ? (\Illuminate\Support\Facades\Lang::has('admin.details.values.'.$gender) ? __('admin.details.values.'.$gender) : Str::headline($gender)) : '—' }}</dd></div>
                    <div><dt>{{ __('admin.details.date_of_birth') }}</dt><dd>{{ $user->date_of_birth?->format('j M Y') ?? '—' }}</dd></div>
                    <div><dt>{{ __('admin.details.joined') }}</dt><dd>{{ $user->created_at->timezone('Africa/Cairo')->format('j M Y') }}</dd></div>
                </dl>
                @if ($revealing)
                    {{-- D15: a reason first, then the number; the reason goes to the audit log. --}}
                    <form wire:submit="reveal" class="ad-confirm">
                        <div class="ad-field">
                            <label class="ad-label" for="reveal-reason">{{ __('admin/profile.reveal_reason') }}</label>
                            <input id="reveal-reason" class="ad-input" wire:model="revealReason" required placeholder="{{ __('admin/profile.reveal_placeholder') }}">
                            @error('revealReason') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-row">
                            <button type="submit" class="ad-btn ad-btn--primary">{{ __('admin/profile.reveal') }}</button>
                            <button type="button" class="ad-btn" wire:click="$set('revealing', false)">{{ __('admin.queue.cancel') }}</button>
                        </div>
                    </form>
                @endif

                <details wire:ignore.self class="ad-details">
                    <summary class="ad-btn ad-btn--sm">{{ __('admin.details.show_all') }}</summary>
                    <x-rq-member-details :member="$user" :may-see-documents="$maySeeDocuments" :revealed="$revealedId === $user->id"
                                         :phone="$revealedPhone ?? PhoneNumber::fromRaw($user->phone_e164)->masked()" />
                </details>
            </x-admin.card>

            <div class="ad-stack">
                <x-admin.card :title="__('admin/profile.activity')" bar>
                    <dl class="ad-facts" style="margin:0">
                        <div><dt>{{ __('admin.details.trips_as_passenger') }}</dt><dd class="ad-num">{{ $user->stats?->completed_trips_as_passenger ?? 0 }}</dd></div>
                        <div><dt>{{ __('admin.details.trips_as_driver') }}</dt><dd class="ad-num">{{ $user->stats?->completed_trips_as_driver ?? 0 }}</dd></div>
                        <div><dt>{{ __('admin.details.no_shows') }}</dt><dd class="ad-num">{{ $user->stats?->no_show_count ?? 0 }}</dd></div>
                        <div><dt>{{ __('admin.details.on_time') }}</dt><dd class="ad-num">{{ $user->stats?->on_time_rate === null ? '—' : number_format((float) $user->stats->on_time_rate).'%' }}</dd></div>
                    </dl>
                </x-admin.card>
                <x-admin.card :title="__('admin/profile.tabs.notes')" bar>
                    @forelse ($notes as $one)
                        <div style="padding-block:6px;border-block-end:1px solid var(--border-subtle)">
                            <div class="ad-row" style="justify-content:space-between"><strong>{{ $one->admin?->name ?? '—' }}</strong><span class="ad-muted ad-num" style="font-size:12px">{{ $one->created_at->timezone('Africa/Cairo')->format('j M H:i') }}</span></div>
                            <p style="margin:4px 0 0;white-space:pre-line" dir="auto">{{ $one->body }}</p>
                        </div>
                    @empty
                        <p class="ad-hint" style="margin:0">{{ __('admin/profile.no_notes') }}</p>
                    @endforelse
                </x-admin.card>
            </div>
        </div>

    @elseif ($tab === 'passenger')
        <x-admin.card flush>
            @if ($bookings->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/profile.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr><th>{{ __('admin/directory.day') }}</th><th>{{ __('admin/directory.driver') }}</th><th class="ad-end">{{ __('admin/directory.seats') }}</th><th class="ad-end">{{ __('admin/directory.price') }}</th><th>{{ __('admin/directory.status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($bookings as $booking)
                        <tr wire:key="b-{{ $booking->id }}">
                            <td data-label="{{ __('admin/directory.day') }}" class="ad-num">{{ $booking->scheduledTrip?->trip_date?->format('D j M Y') }} · {{ $booking->scheduledTrip?->departure_at?->timezone('Africa/Cairo')->format('H:i') }}</td>
                            <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $booking->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $booking->seats_reserved }}</td>
                            <td data-label="{{ __('admin/directory.price') }}" class="ad-end ad-num">{{ $egp((int) $booking->price_snapshot_piastres) }}</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ \App\Livewire\Admin\Directory\DirectoryPage::tone($booking->status->value) }}">{{ __('admin/directory.statuses.'.$booking->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'driver')
        @if ($user->driverProfile === null)
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin/profile.not_a_driver')" /></x-admin.card>
        @else
            <div class="ad-stack">
                <x-admin.card :title="__('admin/profile.cars')" bar>
                    @foreach ($user->driverProfile->vehicles as $vehicle)
                        <div class="ad-row" style="gap:8px;padding-block:6px" wire:key="v-{{ $vehicle->id }}">
                            <x-admin.icon name="car" />
                            <strong style="flex:1"><bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi> · {{ $vehicle->year }} · <span class="ad-mono"><bdi>{{ $vehicle->plate_number }}</bdi></span></strong>
                            @if ($vehicle->is_active)<span class="ad-chip ad-chip--ok">{{ __('admin.details.active_car') }}</span>@endif
                            <span class="ad-chip ad-chip--plain">{{ \Illuminate\Support\Facades\Lang::has('admin.details.values.'.$vehicle->verification_status->value) ? __('admin.details.values.'.$vehicle->verification_status->value) : Str::headline($vehicle->verification_status->value) }}</span>
                        </div>
                    @endforeach
                </x-admin.card>
                <x-admin.card flush :title="__('admin/directory.commutes.title')" bar>
                    @if ($commutes->isEmpty())
                        <x-admin.state kind="empty" :title="__('admin/profile.none')" />
                    @else
                        <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                            <thead><tr><th>{{ __('admin/directory.route') }}</th><th class="ad-end">{{ __('admin/directory.seats') }}</th><th>{{ __('admin/directory.status') }}</th><th>{{ __('admin/directory.created') }}</th></tr></thead>
                            <tbody>
                            @foreach ($commutes as $commute)
                                @php($origin = $commute->locations->firstWhere('type', \App\Domains\Commute\Enums\CommuteLocationType::Origin))
                                @php($destination = $commute->locations->firstWhere('type', \App\Domains\Commute\Enums\CommuteLocationType::Destination))
                                <tr wire:key="c-{{ $commute->id }}">
                                    <td data-label="{{ __('admin/directory.route') }}"><bdi>{{ $origin?->address ?? '—' }}</bdi> <x-admin.icon name="chevron" size="12" class="ad-flip ad-subtle" /> <bdi>{{ $destination?->address ?? '—' }}</bdi></td>
                                    <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $commute->seats_total }}</td>
                                    <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ \App\Livewire\Admin\Directory\DirectoryPage::tone($commute->status->value) }}">{{ __('admin/directory.statuses.'.$commute->status->value) }}</span></td>
                                    <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $commute->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </x-admin.card>
            </div>
        @endif

    @elseif ($tab === 'money')
        <div class="ad-grid ad-grid--2" style="margin-block-end:12px">
            @foreach (['driver' => $driverStanding, 'passenger' => $passengerStanding] as $side => $standing)
                <x-admin.card flush :tone="$standing?->isBlocked() ? 'topline-bad' : null">
                    <x-admin.stat :label="__('admin/profile.balance_'.$side)" :value="$egp(max(0, $standing?->balancePiastres ?? 0))" money
                                  :foot="e(($standing?->balancePiastres ?? 0) < 0 ? __('admin/profile.credit', ['amount' => $egp(abs($standing->balancePiastres))]) : ($standing?->isBlocked() ? __('admin/profile.unpaid_blocked') : __('admin/profile.up_to_date')))" />
                </x-admin.card>
            @endforeach
        </div>
        <x-admin.card flush>
            @if ($entries->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/profile.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr><th>{{ __('admin/money.when') }}</th><th>{{ __('admin/money.type') }}</th><th>{{ __('admin/money.reason') }}</th><th class="ad-end">{{ __('admin/money.amount') }}</th><th class="ad-end">{{ __('admin/money.balance') }}</th></tr></thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="e-{{ $entry->id }}">
                            <td data-label="{{ __('admin/money.when') }}" class="ad-num ad-muted">{{ $entry->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                            <td data-label="{{ __('admin/money.type') }}"><span class="ad-chip ad-chip--plain">{{ __('admin/money.types.'.$entry->type->value) }}</span> <span class="ad-muted">{{ __('admin.payments.side_'.$entry->side->value) }}</span></td>
                            <td data-label="{{ __('admin/money.reason') }}"><span class="ad-code">{{ $entry->reason_code }}</span></td>
                            <td data-label="{{ __('admin/money.amount') }}" class="ad-end ad-num" style="font-weight:700">{{ $entry->amount_piastres < 0 ? '−' : '+' }}{{ number_format(abs($entry->amount_piastres) / 100, 2) }}</td>
                            <td data-label="{{ __('admin/money.balance') }}" class="ad-end ad-num">{{ number_format($entry->balance_after_piastres / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'reliability')
        <div class="ad-grid ad-grid--2" style="align-items:start">
            <x-admin.card flush :title="__('admin/profile.reliability_events')" bar>
                @if ($events->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/profile.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($events as $event)
                        <tr wire:key="r-{{ $event->id }}" @if ($event->reversed_at) style="opacity:.55" @endif>
                            <td><span class="ad-code">{{ $event->kind->value }}</span></td>
                            <td class="ad-num">−{{ $event->weight }}</td>
                            <td class="ad-muted ad-num">{{ $event->occurred_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                            <td>@if ($event->reversed_at)<span class="ad-chip ad-chip--plain">{{ __('admin/profile.reversed') }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
            <x-admin.card flush :title="__('admin/profile.ratings')" bar>
                @if ($ratings->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/profile.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($ratings as $rating)
                        <tr wire:key="rt-{{ $rating->id }}">
                            <td class="ad-num" style="white-space:nowrap">{{ str_repeat('★', (int) $rating->stars) }}</td>
                            <td dir="auto">{{ Str::limit((string) $rating->comment, 90) ?: '—' }}</td>
                            <td class="ad-muted ad-num">{{ $rating->created_at?->timezone('Africa/Cairo')->format('j M') }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
        </div>

    @elseif ($tab === 'reports')
        <x-admin.card flush>
            @if ($incidents->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/profile.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr><th>{{ __('admin/profile.report') }}</th><th>{{ __('admin/profile.direction') }}</th><th>{{ __('admin/directory.status') }}</th><th>{{ __('admin/directory.created') }}</th></tr></thead>
                    <tbody>
                    @foreach ($incidents as $incident)
                        <tr wire:key="i-{{ $incident->id }}">
                            <td data-label="{{ __('admin/profile.report') }}">{{ __('admin.safety.categories.'.$incident->category->value) }} <span class="ad-code">{{ Str::upper(Str::substr($incident->id, -6)) }}</span></td>
                            <td data-label="{{ __('admin/profile.direction') }}">{{ $incident->reported_user_id === $user->id ? __('admin/profile.about_them') : __('admin/profile.by_them') }}</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--plain">{{ __('admin.safety.status.'.$incident->status->value) }}</span></td>
                            <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $incident->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'notifications')
        <x-admin.card flush>
            @if ($notifications->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/profile.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr><th>{{ __('admin/profile.message') }}</th><th>{{ __('admin/profile.type') }}</th><th>{{ __('admin/profile.sent') }}</th><th>{{ __('admin/profile.read') }}</th></tr></thead>
                    <tbody>
                    @foreach ($notifications as $notification)
                        <tr wire:key="n-{{ $notification->id }}">
                            <td data-label="{{ __('admin/profile.message') }}" dir="auto"><strong>{{ $notification->title }}</strong><div class="ad-muted" style="font-size:12px">{{ Str::limit((string) $notification->body, 110) }}</div></td>
                            <td data-label="{{ __('admin/profile.type') }}"><span class="ad-code">{{ $notification->type }}</span></td>
                            <td data-label="{{ __('admin/profile.sent') }}" class="ad-muted ad-num">{{ ($notification->sent_at ?? $notification->created_at)?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                            <td data-label="{{ __('admin/profile.read') }}">{{ $notification->read_at ? __('admin.details.yes') : __('admin.details.no') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'devices')
        <x-admin.card flush>
            @if ($devices->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/profile.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr><th>{{ __('admin/profile.device') }}</th><th>{{ __('admin/profile.app') }}</th><th>{{ __('admin/profile.last_seen') }}</th><th>{{ __('admin/directory.status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($devices as $device)
                        <tr wire:key="d-{{ $device->id }}">
                            <td data-label="{{ __('admin/profile.device') }}">{{ $device->device_model ?? '—' }} <span class="ad-muted">{{ $device->platform?->value }} {{ $device->os_version }}</span></td>
                            <td data-label="{{ __('admin/profile.app') }}" class="ad-num">{{ $device->app_version ?? '—' }}</td>
                            <td data-label="{{ __('admin/profile.last_seen') }}" class="ad-muted ad-num">{{ $device->last_seen_at?->timezone('Africa/Cairo')->format('j M Y H:i') ?? '—' }}</td>
                            <td data-label="{{ __('admin/directory.status') }}">
                                @if ($device->revoked_at)
                                    <span class="ad-chip ad-chip--bad">{{ __('admin/profile.signed_out') }}</span>
                                @else
                                    <span class="ad-chip ad-chip--ok">{{ __('admin/profile.signed_in') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @else
        <div class="ad-split" id="notes">
            <x-admin.card :title="__('admin/profile.tabs.notes')" bar>
                <p class="ad-hint" style="margin:0 0 10px"><x-admin.icon name="lock" size="12" /> {{ __('admin/profile.notes_internal') }}</p>
                <form wire:submit="addNote" style="margin-block-end:12px">
                    <div class="ad-field">
                        <textarea class="ad-textarea" wire:model="note" rows="2" required aria-label="{{ __('admin/profile.note') }}" placeholder="{{ __('admin/profile.note_placeholder') }}"></textarea>
                        @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="ad-btn ad-btn--primary ad-btn--sm" wire:loading.attr="disabled">{{ __('admin/profile.add_note') }}</button>
                </form>
                @forelse ($notes as $one)
                    <div style="padding-block:8px;border-block-start:1px solid var(--border-subtle)" wire:key="note-{{ $one->id }}">
                        <div class="ad-row" style="justify-content:space-between"><strong>{{ $one->admin?->name ?? '—' }}</strong><span class="ad-muted ad-num" style="font-size:12px">{{ $one->created_at->timezone('Africa/Cairo')->format('j M Y H:i') }}</span></div>
                        <p style="margin:4px 0 0;white-space:pre-line" dir="auto">{{ $one->body }}</p>
                    </div>
                @empty
                    <p class="ad-hint" style="margin:0">{{ __('admin/profile.no_notes') }}</p>
                @endforelse
            </x-admin.card>
            <x-admin.card flush :title="__('admin/profile.staff_activity')" bar>
                @if ($activity->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/profile.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($activity as $entry)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                        <tr wire:key="a-{{ $entry->id }}">
                            <td>{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}@if ($entry->reason)<div class="ad-muted" style="font-size:12px" dir="auto">{{ Str::limit($entry->reason, 90) }}</div>@endif</td>
                            <td>{{ $entry->admin?->name ?? '—' }}</td>
                            <td class="ad-muted ad-num">{{ $entry->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
        </div>
    @endif
</div>
