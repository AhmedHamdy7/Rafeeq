{{--
    SYSTEM. Three tabs:
      · Status — is everything running (scheduler, queue worker, Reverb), queued and failed work,
        backups and disk space.
      · Commands — a fixed list of maintenance commands (see App\Livewire\Admin\System for why it
        is a list and not a shell), output shown like a terminal.
      · Errors — the latest warnings and errors from the log, redacted.
    Read-only apart from the commands themselves; nothing here touches a booking or a member.
--}}
@php($fmt = fn (int $bytes) => \App\Console\Commands\BackupNow::size($bytes))
<div>
    <x-admin.page-head :title="__('admin/system.title')" :sub="__('admin/system.sub', ['env' => $environment, 'url' => $url])" />

    <div class="ad-tabs" role="tablist">
        @foreach (['status' => 'chart', 'commands' => 'columns', 'errors' => 'alert'] as $name => $icon)
            <button type="button" role="tab" class="ad-tab" wire:click="$set('tab', '{{ $name }}')" wire:key="systab-{{ $name }}"
                    aria-selected="{{ $tab === $name ? 'true' : 'false' }}">
                <x-admin.icon :name="$icon" size="15" />{{ __('admin/system.tabs.'.$name) }}
            </button>
        @endforeach
        <span wire:loading class="ad-muted" style="align-self:center;margin-inline-start:auto">{{ __('admin/system.running') }}</span>
    </div>

    {{-- ───────────── Status ───────────── --}}
    @if ($tab === 'status')
        <div class="ad-sys-grid">
            @foreach ($services as $name => $state)
                <div @class(['ad-sys-tile', 'is-'.$state]) wire:key="svc-{{ $name }}">
                    <span class="ad-sys-tile__dot" aria-hidden="true"></span>
                    <div>
                        <div class="ad-sys-tile__name">{{ __('admin/system.services.'.$name) }}</div>
                        <div class="ad-sys-tile__state">{{ __('admin/system.states.'.$state) }}</div>
                        @if ($name === 'scheduler' && $heartbeat)
                            <div class="ad-hint">{{ __('admin/system.last_beat', ['time' => $heartbeat->diffForHumans()]) }}</div>
                        @endif
                    </div>
                </div>
            @endforeach

            <div @class(['ad-sys-tile', 'is-'.($queue['failed'] > 0 ? 'stopped' : ($queue['pending'] > 50 ? 'starting' : 'running'))])>
                <span class="ad-sys-tile__dot" aria-hidden="true"></span>
                <div>
                    <div class="ad-sys-tile__name">{{ __('admin/system.queue.title') }}</div>
                    <div class="ad-sys-tile__state">{{ __('admin/system.queue.summary', ['pending' => $queue['pending'], 'failed' => $queue['failed']]) }}</div>
                    @if ($queue['oldest_seconds'] !== null)
                        <div class="ad-hint">{{ __('admin/system.queue.oldest', ['time' => now()->subSeconds($queue['oldest_seconds'])->diffForHumans()]) }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="ad-sys-cols">
            {{-- Backups --}}
            <x-admin.card :title="__('admin/system.backups.title')" bar>
                <x-slot:actions>
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" wire:click="run('backup')"
                            wire:confirm="{{ __('admin/system.confirm.backup') }}" wire:loading.attr="disabled">{{ __('admin/system.commands.backup.label') }}</button>
                </x-slot:actions>

                @if ($backupFresh)
                    <p class="ad-flash" style="margin:0 0 12px"><x-admin.icon name="check" />{{ __('admin/system.backups.fresh') }}</p>
                @else
                    <p class="ad-flash ad-flash--error" style="margin:0 0 12px"><x-admin.icon name="alert" />{{ $backups === [] ? __('admin/system.backups.none') : __('admin/system.backups.stale') }}</p>
                @endif

                @if ($backups !== [])
                    <div class="ad-table-wrap"><table class="ad-table ad-table--dense">
                        <thead><tr>
                            <th>{{ __('admin/system.backups.when') }}</th>
                            <th class="ad-end">{{ __('admin/system.backups.database') }}</th>
                            <th class="ad-end">{{ __('admin/system.backups.files') }}</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        @foreach ($backups as $backup)
                            <tr wire:key="bk-{{ $backup['name'] }}">
                                <td class="ad-num" style="white-space:nowrap">{{ $backup['at']?->timezone('Africa/Cairo')->format('j M Y, H:i') ?? $backup['name'] }}</td>
                                <td class="ad-end ad-num">{{ $fmt($backup['database_bytes']) }}</td>
                                <td class="ad-end ad-num">{{ $fmt($backup['files_bytes']) }}</td>
                                <td class="ad-end">
                                    @if ($backup['ok'])
                                        <span class="ad-chip ad-chip--ok">{{ __('admin/system.backups.ok') }}</span>
                                    @else
                                        <span class="ad-chip ad-chip--bad" title="{{ $backup['error'] }}">{{ __('admin/system.backups.failed') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
                <p class="ad-hint" style="margin:10px 0 0">{{ __('admin/system.backups.hint') }}</p>
            </x-admin.card>

            {{-- Disk --}}
            <x-admin.card :title="__('admin/system.disk.title')" bar>
                @php($used = max(0, $disk['total'] - $disk['free']))
                @php($percent = $disk['total'] > 0 ? (int) round($used / $disk['total'] * 100) : 0)
                <div class="ad-sys-disk">
                    <div class="ad-sys-disk__bar"><span @class(['is-warn' => $percent >= 80]) style="inline-size:{{ $percent }}%"></span></div>
                    <div class="ad-num">{{ __('admin/system.disk.summary', ['free' => $fmt((int) $disk['free']), 'total' => $fmt((int) $disk['total']), 'percent' => $percent]) }}</div>
                </div>
            </x-admin.card>
        </div>

        {{-- Failed jobs --}}
        <x-admin.card :title="__('admin/system.failed.title')" bar flush>
            <x-slot:actions>
                @if ($failedJobs !== [])
                    <button type="button" class="ad-btn ad-btn--sm" wire:click="run('queue_retry_all')"
                            wire:confirm="{{ __('admin/system.confirm.queue_retry_all') }}">{{ __('admin/system.commands.queue_retry_all.label') }}</button>
                @endif
            </x-slot:actions>
            @if ($failedJobs === [])
                <x-admin.state kind="empty" :title="__('admin/system.failed.none')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/system.failed.job') }}</th>
                        <th>{{ __('admin/system.failed.why') }}</th>
                        <th>{{ __('admin/system.failed.when') }}</th>
                        <th class="ad-end"><span class="ad-sr">{{ __('admin/system.run') }}</span></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($failedJobs as $job)
                        <tr wire:key="fj-{{ $job['uuid'] }}">
                            <td data-label="{{ __('admin/system.failed.job') }}"><strong>{{ $job['job'] }}</strong> <span class="ad-code">{{ $job['queue'] }}</span></td>
                            <td data-label="{{ __('admin/system.failed.why') }}" class="ad-mono" dir="ltr" style="text-align:start;font-size:12px">{{ $job['reason'] }}</td>
                            <td data-label="{{ __('admin/system.failed.when') }}" class="ad-muted ad-num" style="white-space:nowrap">{{ $job['failed_at']?->diffForHumans() }}</td>
                            <td class="ad-end" style="white-space:nowrap">
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="retryFailed('{{ $job['uuid'] }}')">{{ __('admin/system.failed.retry') }}</button>
                                <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="forgetFailed('{{ $job['uuid'] }}')"
                                        wire:confirm="{{ __('admin/system.confirm.forget') }}">{{ __('admin/system.failed.forget') }}</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>
    @endif

    {{-- ───────────── Commands ───────────── --}}
    @if ($tab === 'commands')
        <x-admin.card :title="__('admin/system.quick')">
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <button type="button" class="ad-btn ad-btn--primary" wire:click="run('migrate')" wire:loading.attr="disabled"
                        wire:confirm="{{ __('admin/system.confirm.migrate') }}">
                    {{ __('admin/system.commands.migrate.label') }}
                </button>
                <button type="button" class="ad-btn ad-btn--dangerline" wire:click="run('optimize_clear')" wire:loading.attr="disabled"
                        wire:confirm="{{ __('admin/system.confirm.optimize_clear') }}">
                    {{ __('admin/system.commands.optimize_clear.label') }}
                </button>
            </div>
        </x-admin.card>

        <x-admin.card :title="__('admin/system.all')" flush>
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/system.command') }}</th>
                        <th>{{ __('admin/system.what') }}</th>
                        <th class="ad-end"><span class="ad-sr">{{ __('admin/system.run') }}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($commands as $key => [$command, $arguments, $confirm])
                        <tr wire:key="cmd-{{ $key }}">
                            <td data-label="{{ __('admin/system.command') }}">
                                <div style="font-weight:650">{{ __('admin/system.commands.'.$key.'.label') }}</div>
                                <span class="ad-code" dir="ltr">{{ \App\Livewire\Admin\System::display($command, $arguments) }}</span>
                            </td>
                            <td data-label="{{ __('admin/system.what') }}" class="ad-muted">{{ __('admin/system.commands.'.$key.'.hint') }}</td>
                            <td class="ad-end">
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="run('{{ $key }}')" wire:loading.attr="disabled"
                                        @if ($confirm) wire:confirm="{{ __('admin/system.confirm.'.$key) }}" @endif>
                                    {{ __('admin/system.run') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    @endif

    {{-- ───────────── Errors ───────────── --}}
    @if ($tab === 'errors')
        <x-admin.card :title="__('admin/system.errors.title')" bar flush>
            <x-slot:actions>
                <button type="button" class="ad-btn ad-btn--sm" wire:click="$refresh">{{ __('admin/system.errors.refresh') }}</button>
            </x-slot:actions>
            @if ($logEntries === [])
                <x-admin.state kind="empty" :title="__('admin/system.errors.none')" />
            @else
                <ul class="ad-sys-log">
                    @foreach ($logEntries as $i => $entry)
                        <li wire:key="log-{{ $i }}-{{ md5($entry['at'].$entry['message']) }}">
                            <span @class(['ad-chip', 'ad-chip--warn' => $entry['level'] === 'WARNING', 'ad-chip--bad' => $entry['level'] !== 'WARNING'])>{{ $entry['level'] }}</span>
                            <span class="ad-muted ad-num" style="white-space:nowrap">{{ $entry['at'] }}</span>
                            <span class="ad-mono" dir="ltr" style="text-align:start;flex:1;min-inline-size:0;overflow-wrap:anywhere">{{ $entry['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="ad-hint" style="margin:0;padding:10px 16px">{{ __('admin/system.errors.hint') }}</p>
        </x-admin.card>
    @endif

    {{-- The terminal: every run from any tab lands here. --}}
    @if ($tab === 'commands' || $runs !== [])
        <x-admin.card :title="__('admin/system.output')">
            <x-slot:actions>
                @if ($runs !== [])
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="clearScreen">{{ __('admin/system.clear_screen') }}</button>
                @endif
            </x-slot:actions>

            <div dir="ltr" class="ad-sys-term">
                @forelse ($runs as $i => $entry)
                    <div wire:key="run-{{ count($runs) - $i }}-{{ $entry['at'] }}" style="margin-block-end:14px">
                        <div><span style="color:#7ee787">$</span> {{ $entry['command'] }} <span style="color:#8b949e">· {{ $entry['at'] }} · exit</span>
                            <span style="color:{{ $entry['exit'] === 0 ? '#7ee787' : '#ff7b72' }}">{{ $entry['exit'] }}</span></div>
                        <pre style="margin:6px 0 0;white-space:pre-wrap;word-break:break-word;font:inherit;color:inherit">{{ $entry['output'] }}</pre>
                    </div>
                @empty
                    <span style="color:#8b949e">{{ __('admin/system.empty') }}</span>
                @endforelse
            </div>
            <p class="ad-muted" style="margin-block-start:10px">{{ __('admin/system.audited') }}</p>
        </x-admin.card>
    @endif
</div>
