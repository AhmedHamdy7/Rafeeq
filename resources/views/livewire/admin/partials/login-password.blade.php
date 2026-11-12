{{-- S01 / S02. A wrong password and a wrong code read the same, so nobody learns which half was right. --}}
<form wire:submit="submitPassword">
    <h1 class="ad-auth__title">{{ __('admin/auth.sign_in.title') }}</h1>
    <p class="ad-auth__sub">{{ __('admin/auth.sign_in.text') }}</p>

    @error('email')
        <div class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</div>
    @enderror

    <div class="ad-field">
        <label class="ad-label" for="admin-email">{{ __('admin/auth.sign_in.email') }}</label>
        <input id="admin-email" class="ad-input" type="email" wire:model="email" autocomplete="username" required autofocus dir="ltr"
               @error('email') aria-invalid="true" @enderror>
    </div>

    <div class="ad-field">
        <label class="ad-label" for="admin-password">{{ __('admin/auth.sign_in.password') }}</label>
        <input id="admin-password" class="ad-input" type="password" wire:model="password" autocomplete="current-password" required
               @error('email') aria-invalid="true" @enderror>
        @error('password') <span class="ad-error">{{ $message }}</span> @enderror
    </div>

    <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" wire:loading.attr="disabled">
        {{ __('admin/auth.sign_in.continue') }}
    </button>
    <p class="ad-auth__note"><strong class="ad-link">{{ __('admin/auth.sign_in.forgot') }}</strong></p>
</form>
