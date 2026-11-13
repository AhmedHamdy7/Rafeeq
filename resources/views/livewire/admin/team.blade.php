{{-- TEAM AND ROLES (M). Staff, their role and state; invite, change role, reset access, suspend, reactivate. --}}
<div>
    <x-admin.page-head :title="__('admin/team.title')" :sub="__('admin/team.sub')">
        <button type="button" class="ad-btn ad-btn--primary" wire:click="startInvite"><x-admin.icon name="users" />{{ __('admin/team.invite') }}</button>
    </x-admin.page-head>

    @error('team') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    @if ($link)
        {{-- Shown once: only a hash of the token is kept, so a lost link means making a new one. --}}
        <div class="ad-notice ad-notice--warn" style="margin-block-end:16px;flex-direction:column;align-items:stretch" role="status">
            <strong>{{ __('admin/team.link.title', ['name' => $linkFor]) }}</strong>
            <span>{{ __('admin/team.link.text', ['hours' => \App\Domains\Admin\Actions\ManageTeamAction::INVITATION_HOURS]) }}</span>
            <div class="ad-row" x-data="{ copied: false }" style="margin-block-start:6px;flex-wrap:nowrap">
                <input class="ad-input ad-mono" dir="ltr" readonly value="{{ $link }}" x-ref="link" x-on:focus="$el.select()" aria-label="{{ __('admin/team.link.label') }}">
                <button type="button" class="ad-btn" x-on:click="navigator.clipboard.writeText($refs.link.value).then(() => copied = true)">
                    <span x-show="! copied">{{ __('admin/team.link.copy') }}</span><span x-show="copied" x-cloak>{{ __('admin/team.link.copied') }}</span>
                </button>
                <button type="button" class="ad-btn ad-btn--quiet" wire:click="dismissLink">{{ __('admin/team.link.done') }}</button>
            </div>
        </div>
    @endif

    @if ($inviting)
        <x-admin.card :title="__('admin/team.invite')" bar style="margin-block-end:16px">
            <form wire:submit="invite">
                <div class="ad-fields-inline">
                    <div class="ad-field">
                        <label class="ad-label" for="inv-name">{{ __('admin/team.name') }}</label>
                        <input id="inv-name" class="ad-input" wire:model="name" required autocomplete="off">
                        @error('name') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field">
                        <label class="ad-label" for="inv-email">{{ __('admin/team.email') }}</label>
                        <input id="inv-email" class="ad-input" type="email" dir="ltr" wire:model="email" required autocomplete="off">
                        @error('email') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field">
                        <label class="ad-label" for="inv-role">{{ __('admin/team.role') }}</label>
                        <select id="inv-role" class="ad-select" wire:model="role" required>
                            <option value="">—</option>
                            @foreach ($roles as $option)
                                <option value="{{ $option->value }}">{{ __('admin/shell.roles.'.$option->value) }}</option>
                            @endforeach
                        </select>
                        @error('role') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <p class="ad-hint" style="margin:0 0 12px">{{ __('admin/team.invite_hint') }}</p>
                <div class="ad-row">
                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin/team.make_link') }}</button>
                    <button type="button" class="ad-btn" wire:click="$set('inviting', false)">{{ __('admin.queue.cancel') }}</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card flush>
        <div class="ad-table-wrap">
            <table class="ad-table ad-table--cards">
                <thead><tr>
                    <th>{{ __('admin/team.name') }}</th>
                    <th>{{ __('admin/team.role') }}</th>
                    <th>{{ __('admin/team.state') }}</th>
                    <th>{{ __('admin/team.authenticator') }}</th>
                    <th>{{ __('admin/team.last_sign_in') }}</th>
                    <th><span class="ad-sr">{{ __('admin/team.actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach ($staff as $person)
                    @php
                        $role = $person->roles->first()?->name;
                        $self = $person->is($me);
                        $state = $person->status->value;
                    @endphp
                    <tr wire:key="staff-{{ $person->id }}">
                        <td data-label="{{ __('admin/team.name') }}">
                            <strong>{{ $person->name }}</strong> @if ($self)<span class="ad-chip ad-chip--plain">{{ __('admin/team.you') }}</span>@endif
                            <div class="ad-muted" style="font-size:12px" dir="ltr">{{ $person->email }}</div>
                        </td>
                        <td data-label="{{ __('admin/team.role') }}">
                            @if ($editing === $person->id)
                                <form wire:submit="saveRole" class="ad-row" style="gap:6px;flex-wrap:nowrap">
                                    <select class="ad-select" style="inline-size:auto" wire:model="newRole" aria-label="{{ __('admin/team.role') }}">
                                        @foreach ($roles as $option)
                                            <option value="{{ $option->value }}">{{ __('admin/shell.roles.'.$option->value) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">{{ __('admin/team.save') }}</button>
                                    <button type="button" class="ad-btn ad-btn--sm" wire:click="$set('editing', null)">{{ __('admin.queue.cancel') }}</button>
                                </form>
                            @else
                                <span class="ad-chip ad-chip--brand ad-chip--plain">{{ $role ? __('admin/shell.roles.'.$role) : '—' }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('admin/team.state') }}">
                            <span class="ad-chip ad-chip--{{ ['active' => 'ok', 'invited' => 'warn', 'suspended' => 'bad'][$state] ?? 'plain' }}">{{ __('admin/team.states.'.$state) }}</span>
                        </td>
                        <td data-label="{{ __('admin/team.authenticator') }}">
                            {{ $person->hasMfaConfirmed() ? __('admin/team.mfa_set') : __('admin/team.mfa_not_set') }}
                        </td>
                        <td data-label="{{ __('admin/team.last_sign_in') }}" class="ad-muted ad-num">{{ $person->last_login_at?->timezone('Africa/Cairo')->format('j M Y H:i') ?? '—' }}</td>
                        <td class="ad-end">
                            @unless ($self || $editing === $person->id)
                                <div class="ad-row" style="justify-content:flex-end;gap:6px">
                                    <button type="button" class="ad-btn ad-btn--xs" wire:click="editRole('{{ $person->id }}')">{{ __('admin/team.change_role') }}</button>
                                    <button type="button" class="ad-btn ad-btn--xs" wire:click="resetAccess('{{ $person->id }}')" wire:confirm="{{ __('admin/team.reset_confirm', ['name' => $person->name]) }}">{{ $state === 'invited' ? __('admin/team.new_link') : __('admin/team.reset_access') }}</button>
                                    @if ($state === 'suspended')
                                        <button type="button" class="ad-btn ad-btn--xs ad-btn--primary" wire:click="reactivate('{{ $person->id }}')">{{ __('admin/team.reactivate') }}</button>
                                    @else
                                        <button type="button" class="ad-btn ad-btn--xs ad-btn--dangerline" wire:click="suspend('{{ $person->id }}')" wire:confirm="{{ __('admin/team.suspend_confirm', ['name' => $person->name]) }}">{{ __('admin/team.suspend') }}</button>
                                    @endif
                                </div>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>

    {{-- M03 · What each role may do. Read-only: roles are set in code, so the matrix cannot drift from what is enforced. --}}
    <div class="ad-section-label">{{ __('admin/team.matrix_title') }}</div>
    <x-admin.card flush>
        <div class="ad-table-wrap"><table class="ad-table ad-table--dense">
            <thead><tr>
                <th>{{ __('admin/team.permission') }}</th>
                @foreach ($roles as $matrixRole)
                    <th class="ad-end">{{ __('admin/shell.roles.'.$matrixRole->value) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
            @foreach (\App\Domains\Admin\Enums\AdminPermission::cases() as $permission)
                <tr>
                    <td><span class="ad-code">{{ $permission->value }}</span></td>
                    @foreach ($roles as $matrixRole)
                        <td class="ad-end">@if (in_array($permission, $matrixRole->permissions(), true))<x-admin.icon name="check" size="14" /><span class="ad-sr">{{ __('admin/team.allowed') }}</span>@else<span class="ad-subtle" aria-hidden="true">—</span><span class="ad-sr">{{ __('admin/team.not_allowed') }}</span>@endif</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-admin.card>
</div>
