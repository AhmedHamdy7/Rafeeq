{{--
    Two steps, one component. Step two replaces step one rather than appearing beneath
    it, so there is never a password field on screen next to a code field.
--}}
<div style="background:#fff;border:1px solid #e8e4dc;border-radius:16px;padding:26px">
    <h1 style="margin:0 0 4px;font-size:19px">{{ __('admin.title') }}</h1>
    <p style="margin:0 0 20px;font-size:13.5px;color:#6b6459">{{ __('admin.login.subtitle') }}</p>

    @if (session('status'))
        <p style="margin:0 0 16px;padding:10px 12px;border-radius:9px;background:#fdf1e3;color:#8a5a1a;font-size:13px">{{ session('status') }}</p>
    @endif

    @if ($pendingAdminId === null)
        <form wire:submit="submitPassword">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">{{ __('admin.login.email') }}</label>
            <input type="email" wire:model="email" autocomplete="username" required
                   style="width:100%;padding:10px 12px;border:1px solid #ddd8ce;border-radius:9px;font-size:14px;margin-bottom:4px">
            @error('email') <p style="margin:0 0 10px;color:#b4232a;font-size:12.5px">{{ $message }}</p> @enderror

            <label style="display:block;font-size:13px;font-weight:600;margin:12px 0 5px">{{ __('admin.login.password') }}</label>
            <input type="password" wire:model="password" autocomplete="current-password" required
                   style="width:100%;padding:10px 12px;border:1px solid #ddd8ce;border-radius:9px;font-size:14px;margin-bottom:4px">
            @error('password') <p style="margin:0 0 10px;color:#b4232a;font-size:12.5px">{{ $message }}</p> @enderror

            <button type="submit" style="width:100%;margin-top:18px;padding:12px;border:none;border-radius:999px;background:#4c3d8f;color:#fff;font-size:14.5px;font-weight:600;cursor:pointer">
                {{ __('admin.login.continue') }}
            </button>
        </form>
    @else
        <form wire:submit="submitCode">
            <p style="margin:0 0 14px;font-size:13.5px;color:#6b6459">{{ __('admin.login.code_hint') }}</p>

            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">{{ __('admin.login.code') }}</label>
            {{-- inputmode numeric, but the value stays a STRING so a leading zero survives. --}}
            <input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" required autofocus
                   style="width:100%;padding:10px 12px;border:1px solid #ddd8ce;border-radius:9px;font-size:20px;letter-spacing:.3em;text-align:center">
            @error('code') <p style="margin:8px 0 0;color:#b4232a;font-size:12.5px">{{ $message }}</p> @enderror

            <button type="submit" style="width:100%;margin-top:18px;padding:12px;border:none;border-radius:999px;background:#4c3d8f;color:#fff;font-size:14.5px;font-weight:600;cursor:pointer">
                {{ __('admin.login.verify') }}
            </button>
        </form>
    @endif
</div>
