{{-- REPORT CASE (R02, P02). Evidence after the first decision is marked new. Decisions reuse the queue's panel. --}}
@php
    use Illuminate\Support\Facades\Lang;

    $mayEvidence = auth('admin')->user()?->can('safety.view_evidence') === true;
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.reports') }}" wire:navigate>{{ __('admin/reports.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($report->id, -6)) }}</span>
    </nav>

    <x-admin.page-head :title="__('admin.safety.categories.'.$report->category->value)" :sub="__('admin.safety.status.'.$report->status->value)" />

    @if (session('status'))
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            @if ($report->isOpen())
                {{-- The decision panel: the same as on the queue, for this case. --}}
                @include('livewire.admin.partials.reports-list')
            @else
                <x-admin.card :title="__('admin/reports.outcome')" bar>
                    <p style="margin:0" dir="auto">{{ $report->resolution ?? '—' }}</p>
                    <p class="ad-muted" style="margin:6px 0 0;font-size:12.5px">{{ $report->resolved_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</p>
                </x-admin.card>
            @endif

            <x-admin.card flush :title="__('admin/reports.evidence_title')" bar>
                @if ($report->evidence->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/reports.no_evidence')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($report->evidence->sortBy('created_at') as $file)
                        @php($isNew = $firstDecision !== null && $file->created_at?->gt($firstDecision))
                        <tr wire:key="ev-{{ $file->id }}" @if ($isNew) style="background:var(--warning-50, #fff7ed)" @endif>
                            <td>{{ __('admin.files.open', ['what' => $loop->iteration]) }} @if ($file->kind)<span class="ad-muted">· {{ $file->kind }}</span>@endif</td>
                            <td>@if ($isNew)<span class="ad-chip ad-chip--warn">{{ __('admin/reports.new_evidence') }}</span>@endif</td>
                            <td class="ad-muted ad-num">{{ $file->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                            <td class="ad-end">@if ($mayEvidence)<a class="ad-link" href="{{ route('admin.files.evidence', $file->id) }}" target="_blank" rel="noopener">{{ __('admin/records.open') }}</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                    @if ($firstDecision)
                        <p class="ad-hint" style="margin:8px 12px">{{ __('admin/reports.new_evidence_hint', ['at' => $firstDecision->timezone('Africa/Cairo')->format('j M H:i')]) }}</p>
                    @endif
                @endif
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card :title="__('admin/reports.people')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/reports.reporter') }}</dt><dd>@if ($report->reporter)<a class="ad-link" href="{{ route('admin.members.show', $report->reporter->id) }}" wire:navigate><bdi>{{ $report->reporter->full_name }}</bdi></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/reports.about') }}</dt><dd>@if ($report->reportedUser)<a class="ad-link" href="{{ route('admin.members.show', $report->reportedUser->id) }}" wire:navigate><bdi>{{ $report->reportedUser->full_name }}</bdi></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/records.booking.title') }}</dt><dd>@if ($report->booking_id)<a class="ad-link" href="{{ route('admin.bookings.show', $report->booking_id) }}" wire:navigate>{{ __('admin/records.open') }}</a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/reports.severity') }}</dt><dd>{{ __('admin.safety.severity.'.$report->severity->value) }}</dd></div>
                    <div><dt>{{ __('admin/reports.opened') }}</dt><dd class="ad-num">{{ $report->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card :title="__('admin/safety.timeline')" bar>
                <ol class="ad-timeline">
                    <li class="ad-timeline__item"><span class="ad-timeline__time ad-num">{{ $report->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span><span class="ad-timeline__body"><strong>{{ __('admin/reports.filed') }}</strong></span></li>
                    @foreach ($report->appeals as $appeal)
                        <li class="ad-timeline__item ad-timeline__item--bad"><span class="ad-timeline__time ad-num">{{ $appeal->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span><span class="ad-timeline__body"><strong>{{ __('admin.safety.appeal_round', ['round' => $appeal->round]) }}</strong><span class="ad-muted" style="display:block;font-size:12.5px" dir="auto">{{ \Illuminate\Support\Str::limit($appeal->reason, 160) }}</span></span></li>
                    @endforeach
                    @foreach ($steps as $step)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $step->action))
                        <li class="ad-timeline__item">
                            <span class="ad-timeline__time ad-num">{{ $step->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span>
                            <span class="ad-timeline__body"><strong>{{ Lang::has($label) ? __($label) : $step->action }}</strong> <span class="ad-muted" style="font-size:12px">· {{ $step->admin?->name ?? '—' }}</span>@if ($step->reason)<span class="ad-muted" style="display:block;font-size:12.5px" dir="auto">{{ \Illuminate\Support\Str::limit($step->reason, 160) }}</span>@endif</span>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>
    </div>
</div>
