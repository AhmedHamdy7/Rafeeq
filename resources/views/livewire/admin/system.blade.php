{{--
    SYSTEM. A fixed list of maintenance commands (see App\Livewire\Admin\System for why it is a
    list and not a shell), each a button; the output of every run shows below like a terminal.
--}}
<div>
    <x-admin.page-head :title="__('admin/system.title')" :sub="__('admin/system.sub', ['env' => $environment, 'url' => $url])" />

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
                            <span class="ad-code" dir="ltr">php artisan {{ $command }}@foreach (array_keys($arguments) as $flag) {{ $flag }}@endforeach</span>
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

    <x-admin.card :title="__('admin/system.output')">
        <x-slot:actions>
            <span wire:loading class="ad-muted">{{ __('admin/system.running') }}</span>
            @if ($runs !== [])
                <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="clearScreen">{{ __('admin/system.clear_screen') }}</button>
            @endif
        </x-slot:actions>

        <div dir="ltr" style="background:#0d1117;color:#c9d1d9;border-radius:10px;padding:14px 16px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.55;max-block-size:560px;overflow:auto;text-align:start">
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
</div>
