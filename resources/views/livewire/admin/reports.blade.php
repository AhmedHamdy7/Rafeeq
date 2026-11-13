{{-- REPORTS AND DISPUTES (R01–R0x). Polls like the safety desk: a report's deadline moves while the page is open. --}}
<div wire:poll.30s.visible>
    <x-admin.page-head :title="__('admin/reports.title')" :sub="trans_choice('admin.safety.reports_open', $reports->total(), ['count' => $reports->total()]).' · '.__('admin/reports.sub')">
        @can('safety.view')
            <a class="ad-btn" href="{{ route('admin.safety') }}" wire:navigate><x-admin.icon name="alert" />{{ __('admin/shell.nav.sos') }}</a>
        @endcan
    </x-admin.page-head>

    @if (session('status'))
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
    @endif

    <div class="ad-toolbar" style="margin-block-end:12px">
        <div class="ad-seg" role="group">
            <button type="button" wire:click="$set('view', 'open')" aria-pressed="{{ $view === 'open' ? 'true' : 'false' }}">{{ __('admin/reports.views.open') }}</button>
            <button type="button" wire:click="$set('view', 'closed')" aria-pressed="{{ $view === 'closed' ? 'true' : 'false' }}">{{ __('admin/reports.views.closed') }}</button>
        </div>
        @if ($view === 'closed')
            <input type="search" class="ad-input" style="max-inline-size:280px" wire:model.live.debounce.400ms="search" placeholder="{{ __('admin/reports.search') }}" aria-label="{{ __('admin/reports.search') }}">
        @endif
    </div>

    @if ($view === 'closed')
        <x-admin.card flush>
            @if ($closed->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/reports.no_closed')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/reports.category') }}</th>
                        <th>{{ __('admin/reports.reporter') }}</th>
                        <th>{{ __('admin/reports.about') }}</th>
                        <th>{{ __('admin/reports.outcome') }}</th>
                        <th>{{ __('admin/reports.closed_at') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($closed as $incident)
                        <tr wire:key="closed-{{ $incident->id }}">
                            <td data-label="{{ __('admin/reports.category') }}">{{ \Illuminate\Support\Facades\Lang::has('admin.safety.categories.'.$incident->category->value) ? __('admin.safety.categories.'.$incident->category->value) : $incident->category->value }}</td>
                            <td data-label="{{ __('admin/reports.reporter') }}">@if ($incident->reporter)<a class="ad-link" href="{{ route('admin.members.show', $incident->reporter->id) }}" wire:navigate><bdi>{{ $incident->reporter->full_name }}</bdi></a>@else — @endif</td>
                            <td data-label="{{ __('admin/reports.about') }}">@if ($incident->reportedUser)<a class="ad-link" href="{{ route('admin.members.show', $incident->reportedUser->id) }}" wire:navigate><bdi>{{ $incident->reportedUser->full_name }}</bdi></a>@else — @endif</td>
                            <td data-label="{{ __('admin/reports.outcome') }}"><span class="ad-chip">{{ __('admin/directory.statuses.'.$incident->status->value) }}</span> <span class="ad-muted" style="font-size:12.5px" dir="auto">{{ \Illuminate\Support\Str::limit((string) $incident->resolution, 80) }}</span></td>
                            <td data-label="{{ __('admin/reports.closed_at') }}" class="ad-num ad-muted">{{ ($incident->resolved_at ?? $incident->updated_at)?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <x-rq-pager :page="$closed" />
            @endif
        </x-admin.card>
    @else
        @include('livewire.admin.partials.reports-list')
    @endif
</div>
