{{-- FIRST SIGN-IN (S05 set a password → S06 set up the authenticator). --}}
<div>
    @if ($invitation === null)
        <h1 class="ad-auth__title">{{ __('admin/team.invitation.expired_title') }}</h1>
        <p class="ad-auth__sub">{{ __('admin/team.invitation.expired') }}</p>
        <a class="ad-btn ad-btn--primary ad-btn--block" href="{{ route('admin.login') }}">{{ __('admin/auth.signed_out.again') }}</a>

    @elseif ($step === 1)
        <form wire:submit="choosePassword">
            <h1 class="ad-auth__title">{{ __('admin/team.invitation.welcome', ['name' => \Illuminate\Support\Str::of($admin->name)->before(' ')]) }}</h1>
            <p class="ad-auth__sub">
                {!! __('admin/team.invitation.intro', ['by' => e($invitedBy ?? __('admin/team.invitation.an_admin')), 'role' => '<strong>'.e($role ? __('admin/shell.roles.'.$role) : '—').'</strong>']) !!}
            </p>
            <div class="ad-field">
                <label class="ad-label" for="inv-password">{{ __('admin/account.password.new') }}</label>
                <input id="inv-password" class="ad-input" type="password" wire:model="password" autocomplete="new-password" required autofocus @error('password') aria-invalid="true" @enderror>
                <span class="ad-hint">{{ __('admin/account.password.rule', ['min' => \App\Domains\Admin\Actions\ChangeAdminPasswordAction::MIN_LENGTH]) }}</span>
                @error('password') <span class="ad-error">{{ $message }}</span> @enderror
            </div>
            <div class="ad-field">
                <label class="ad-label" for="inv-repeat">{{ __('admin/account.password.repeat') }}</label>
                <input id="inv-repeat" class="ad-input" type="password" wire:model="password_confirmation" autocomplete="new-password" required>
            </div>
            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" wire:loading.attr="disabled">{{ __('admin/auth.sign_in.continue') }}</button>
            <p class="ad-auth__note">{{ __('admin/team.invitation.step', ['n' => 1]) }}</p>
        </form>

    @else
        <form wire:submit="finish">
            <h1 class="ad-auth__title">{{ __('admin/team.invitation.mfa_title') }}</h1>
            <p class="ad-auth__sub">{{ __('admin/team.invitation.mfa_text') }}</p>

            <div class="ad-subcard" style="text-align:center;margin-block:0 14px">
                <div class="ad-hint">{{ __('admin/team.invitation.key') }}</div>
                <div class="ad-mono" dir="ltr" style="font-size:16px;font-weight:700;letter-spacing:.06em;margin-block:6px;user-select:all">{{ $secret }}</div>
                <a class="ad-btn ad-btn--sm" href="{{ $otpauth }}">{{ __('admin/team.invitation.open_app') }}</a>
            </div>

            <div class="ad-field">
                <label class="ad-label" for="inv-code">{{ __('admin/auth.code.label') }}</label>
                <input id="inv-code" class="ad-input ad-mono" dir="ltr" style="text-align:center;font-size:20px;letter-spacing:.3em" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" wire:model="code" required @error('code') aria-invalid="true" @enderror>
                @error('code') <span class="ad-error">{{ $message }}</span> @enderror
                @error('password') <span class="ad-error">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" wire:loading.attr="disabled">{{ __('admin/team.invitation.finish') }}</button>
            <div class="ad-row" style="justify-content:space-between;margin-block-start:12px">
                <button type="button" class="ad-btn ad-btn--quiet ad-btn--sm" wire:click="back">{{ __('admin/team.invitation.back') }}</button>
                <span class="ad-hint">{{ __('admin/team.invitation.step', ['n' => 2]) }}</span>
            </div>
        </form>
    @endif
</div>
