{{-- My account (MY1): account · preferences · my recent activity. --}}
<div>
    <x-admin.page-head :title="__('admin/account.title')" />

    <div class="ad-grid ad-grid--3" style="align-items:start">
        <x-admin.card :title="__('admin/account.account')" bar>
            @if ($changed)
                <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ __('admin/account.password.changed') }}</p>
            @endif

            <dl class="ad-kv" style="margin-block-end:16px">
                <dt>{{ __('admin/account.name') }}</dt>
                <dd>{{ $admin->name }}</dd>
                <dt>{{ __('admin/account.email') }}</dt>
                <dd dir="ltr" style="text-align:start">{{ $admin->email }}</dd>
            </dl>
            <p class="ad-hint" style="margin:0 0 16px">{{ __('admin/account.ask_admin') }}</p>

            @if (! $changing)
                <button type="button" class="ad-btn" wire:click="startPasswordChange">
                    <x-admin.icon name="lock" />{{ __('admin/account.password.change') }}
                </button>
            @else
                <form wire:submit="changePassword">
                    <div class="ad-field">
                        <label class="ad-label" for="pw-current">{{ __('admin/account.password.current') }}</label>
                        <input id="pw-current" class="ad-input" type="password" wire:model="current" autocomplete="current-password" required
                               @error('current') aria-invalid="true" @enderror>
                        @error('current') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field">
                        <label class="ad-label" for="pw-new">{{ __('admin/account.password.new') }}</label>
                        <input id="pw-new" class="ad-input" type="password" wire:model="new" autocomplete="new-password" required
                               minlength="{{ \App\Domains\Admin\Actions\ChangeAdminPasswordAction::MIN_LENGTH }}"
                               @error('new') aria-invalid="true" @enderror>
                        <span class="ad-hint">{{ __('admin/account.password.rule', ['min' => \App\Domains\Admin\Actions\ChangeAdminPasswordAction::MIN_LENGTH]) }}</span>
                        @error('new') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field">
                        <label class="ad-label" for="pw-repeat">{{ __('admin/account.password.repeat') }}</label>
                        <input id="pw-repeat" class="ad-input" type="password" wire:model="new_confirmation" autocomplete="new-password" required>
                    </div>
                    <div class="ad-row">
                        <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin/account.password.save') }}</button>
                        <button type="button" class="ad-btn" wire:click="cancelPasswordChange">{{ __('admin/account.cancel') }}</button>
                    </div>
                </form>
            @endif

            <p class="ad-hint" style="margin:16px 0 0">{{ __('admin/account.two_factor') }}</p>
        </x-admin.card>

        <x-admin.card :title="__('admin/account.preferences')" bar>
            <div class="ad-section-label" style="margin-block-start:0">{{ __('admin/account.language') }}</div>
            <div class="ad-seg">
                @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
                    <form method="POST" action="{{ route('admin.locale', $code) }}" style="display:contents">
                        @csrf
                        <button type="submit" lang="{{ $code }}" aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}">{{ $name }}</button>
                    </form>
                @endforeach
            </div>

            <div class="ad-section-label">{{ __('admin/account.theme') }}</div>
            {{-- Remembered in this browser, like the moon / sun in the top bar. "System" follows the device. --}}
            <div
                class="ad-seg"
                x-data="{
                    choice: (() => { try { return localStorage.getItem('rq-theme') || 'system' } catch (e) { return 'system' } })(),
                    pick(value) {
                        this.choice = value;
                        const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                        try { value === 'system' ? localStorage.removeItem('rq-theme') : localStorage.setItem('rq-theme', value) } catch (e) {}
                        document.documentElement.dataset.theme = value === 'system' ? system : value;
                    },
                }"
            >
                @foreach (['light', 'dark', 'system'] as $theme)
                    <button type="button" x-on:click="pick('{{ $theme }}')" x-bind:aria-pressed="choice === '{{ $theme }}' ? 'true' : 'false'">{{ __('admin/account.themes.'.$theme) }}</button>
                @endforeach
            </div>

            <div class="ad-section-label">{{ __('admin/account.role') }}</div>
            @if ($role)
                <span class="ad-chip ad-chip--brand ad-chip--plain">{{ __('admin/shell.roles.'.$role) }}</span>
            @endif
        </x-admin.card>

        <x-admin.card flush>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                    <tr>
                        <th>{{ __('admin/account.recent') }}</th>
                        <th class="ad-end">{{ __('admin/account.when') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($recent as $entry)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                        <tr>
                            <td>{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}</td>
                            <td class="ad-end ad-num ad-muted" title="{{ $entry->created_at->toDayDateTimeString() }}">
                                {{ $entry->created_at->isToday() ? $entry->created_at->format('H:i') : $entry->created_at->format('j M') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2"><x-admin.state kind="empty" :title="__('admin/account.no_activity')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    </div>
</div>
