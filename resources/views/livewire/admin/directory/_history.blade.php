{{-- What staff did to this record (from the audit log), newest first. Needs $history. --}}
<x-admin.card flush :title="__('admin/records.history')" bar>
    @if ($history->isEmpty())
        <x-admin.state kind="empty" :title="__('admin/records.no_history')" />
    @else
        <table class="ad-table ad-table--dense"><tbody>
        @foreach ($history as $entry)
            @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
            <tr wire:key="h-{{ $entry->id }}">
                <td>{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}@if ($entry->reason)<div class="ad-muted" style="font-size:12px" dir="auto">{{ \Illuminate\Support\Str::limit($entry->reason, 120) }}</div>@endif</td>
                <td>{{ $entry->admin?->name ?? '—' }}</td>
                <td class="ad-muted ad-num" style="white-space:nowrap">{{ $entry->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
            </tr>
        @endforeach
        </tbody></table>
    @endif
</x-admin.card>
