{{-- VEHICLE DETAIL (E03–E05, E07). --}}
@php
    use App\Domains\Commute\Enums\CommuteLocationType;
    use App\Livewire\Admin\Directory\DirectoryPage;

    $status = $vehicle->verification_status?->value ?? 'pending';
    $driver = $vehicle->driverProfile?->user;
    $byReport = $status === 'suspended' && $vehicle->suspension_reason === 'report';
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.vehicles') }}" wire:navigate>{{ __('admin/directory.vehicles.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-mono"><bdi>{{ $vehicle->plate_number }}</bdi></span>
    </nav>

    <x-admin.page-head :title="trim($vehicle->make.' '.$vehicle->model.' '.$vehicle->year)" :sub="$vehicle->plate_number">
        @if ($mayAct)
            @if ($status === 'approved')
                <button type="button" class="ad-btn ad-btn--dangerline" wire:click="start('suspend')">{{ __('admin/records.vehicle.suspend') }}</button>
            @elseif ($byReport)
                <button type="button" class="ad-btn" wire:click="start('reinstate')">{{ __('admin/records.vehicle.reinstate') }}</button>
            @endif
            @if ($vehicle->registration_renewal_submitted_at)
                <button type="button" class="ad-btn ad-btn--primary" wire:click="start('approve_renewal')">{{ __('admin/records.vehicle.approve_renewal') }}</button>
                <button type="button" class="ad-btn" wire:click="start('reject_renewal')">{{ __('admin/records.vehicle.reject_renewal') }}</button>
            @endif
        @endif
    </x-admin.page-head>

    @if ($done)
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ $done }}</p>
    @endif

    @if ($acting)
        <x-admin.card :title="__('admin/records.vehicle.'.$acting)" bar style="margin-block-end:12px">
            <form wire:submit="act">
                <p class="ad-hint" style="margin:0 0 12px">{{ __('admin/records.vehicle.hints.'.$acting) }}</p>
                @if ($acting === 'approve_renewal')
                    <div class="ad-field">
                        <label class="ad-label" for="veh-date">{{ __('admin/records.vehicle.new_expiry') }}</label>
                        <input id="veh-date" type="date" class="ad-input" wire:model="renewalDate" required>
                        @error('renewalDate') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                @elseif ($acting !== 'reinstate')
                    <div class="ad-field">
                        <label class="ad-label" for="veh-reason">{{ __('admin/actions.reason') }}</label>
                        <textarea id="veh-reason" class="ad-textarea" rows="2" dir="auto" wire:model="reason" required></textarea>
                        @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                @endif
                <div class="ad-row">
                    <button type="submit" @class(['ad-btn', 'ad-btn--danger' => $acting === 'suspend', 'ad-btn--primary' => $acting !== 'suspend']) wire:loading.attr="disabled">{{ __('admin/records.vehicle.confirm.'.$acting) }}</button>
                    <button type="button" class="ad-btn" wire:click="$set('acting', null)">{{ __('admin.queue.cancel') }}</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/records.vehicle.car')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/directory.status') }}</dt><dd><span class="ad-chip ad-chip--{{ ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad', 'suspended' => 'bad'][$status] ?? 'plain' }}">{{ __('admin/records.vehicle.statuses.'.$status) }}</span>@if ($vehicle->suspension_reason) <span class="ad-muted">· {{ __('admin/records.vehicle.reasons.'.$vehicle->suspension_reason) }}</span>@endif</dd></div>
                    <div><dt>{{ __('admin/directory.driver') }}</dt><dd>@if ($driver)<a class="ad-link" href="{{ route('admin.members.show', $driver->id) }}" wire:navigate><bdi>{{ $driver->full_name }}</bdi></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/records.vehicle.colour') }}</dt><dd><bdi>{{ $vehicle->colour ?? '—' }}</bdi></dd></div>
                    <div><dt>{{ __('admin/directory.seats') }}</dt><dd class="ad-num">{{ $vehicle->approved_seats ?? $vehicle->seats }}</dd></div>
                    <div><dt>{{ __('admin/stats.filters.tier') }}</dt><dd>{{ __('admin/stats.filters.tiers.'.$vehicle->tierOrDefault()->value) }}</dd></div>
                    <div><dt>{{ __('admin/records.vehicle.ac') }}</dt><dd>{{ $vehicle->has_ac ? __('admin/records.yes') : __('admin/records.no') }}</dd></div>
                    @if ($vehicle->rejection_reason)
                        <div><dt>{{ __('admin/records.vehicle.last_refusal') }}</dt><dd dir="auto">{{ $vehicle->rejection_reason }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card flush :title="__('admin/records.vehicle.papers')" bar>
                @if ($vehicle->documents->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($vehicle->documents as $document)
                        @php($expires = $document->expires_at)
                        <tr wire:key="doc-{{ $document->id }}">
                            <td>{{ __('admin/records.vehicle.documents.'.($document->type?->value ?? 'other')) }}</td>
                            <td class="ad-num">
                                @if ($expires)
                                    <span @class(['ad-chip', 'ad-chip--bad' => $expires->isPast(), 'ad-chip--warn' => ! $expires->isPast() && $expires->lt(now()->addDays(30))])>{{ $expires->format('j M Y') }}</span>
                                @else — @endif
                            </td>
                            <td class="ad-end">
                                @can('verification.view_document')
                                    <a class="ad-link" href="{{ route('admin.files.vehicle-document', $document->id) }}" target="_blank" rel="noopener">{{ __('admin/records.open') }}</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card flush :title="__('admin/records.vehicle.commutes')" bar>
                @if ($commutes->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($commutes as $commute)
                        <tr wire:key="vc-{{ $commute->id }}">
                            <td><a class="ad-link" href="{{ route('admin.commutes.show', $commute->id) }}" wire:navigate><bdi>{{ $commute->locations->firstWhere('type', CommuteLocationType::Origin)?->address ?? '—' }}</bdi> → <bdi>{{ $commute->locations->firstWhere('type', CommuteLocationType::Destination)?->address ?? '—' }}</bdi></a></td>
                            <td><span class="ad-chip ad-chip--{{ DirectoryPage::tone($commute->status->value) }}">{{ __('admin/directory.statuses.'.$commute->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>

            @include('livewire.admin.directory._history')
        </div>
    </div>
</div>
