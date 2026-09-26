{{--
    Two steps, one component. Step two REPLACES step one rather than appearing beneath it,
    so there is never a password field on screen next to a code field.
--}}
<div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
        <span class="rq-brand__mark" aria-hidden="true">ر</span>
        <div>
            <h1 class="rq-h1" style="margin:0">{{ __('admin.title') }}</h1>
            <p class="rq-note" style="margin:0">{{ __('admin.login.subtitle') }}</p>
        </div>
    </div>

    @if (session('status'))
        <p class="rq-flash" style="background:var(--warn-100);color:var(--warn-600)">{{ session('status') }}</p>
    @endif

    @if ($pendingAdminId === null)
        <form wire:submit="submitPassword">
            <label class="rq-label" for="admin-email">{{ __('admin.login.email') }}</label>
            <input id="admin-email" class="rq-field" type="email" wire:model="email" autocomplete="username" required>
            @error('email') <p class="rq-error">{{ $message }}</p> @enderror

            <label class="rq-label" for="admin-password" style="margin-top:14px">{{ __('admin.login.password') }}</label>
            <input id="admin-password" class="rq-field" type="password" wire:model="password" autocomplete="current-password" required>
            @error('password') <p class="rq-error">{{ $message }}</p> @enderror

            <button type="submit" class="rq-btn rq-btn--primary" style="width:100%;margin-top:20px" wire:loading.attr="disabled">
                {{ __('admin.login.continue') }}
            </button>
        </form>
    @else
        <form wire:submit="submitCode">
            <p class="rq-note" style="margin:0 0 14px">{{ __('admin.login.code_hint') }}</p>

            <label class="rq-label" for="admin-code">{{ __('admin.login.code') }}</label>
            {{--
                inputmode numeric for a phone keypad, but the value stays a STRING so a
                leading zero survives — a numeric cast would turn 012345 into 12345 and
                refuse a perfectly good code.
            --}}
            <input
                id="admin-code"
                class="rq-field rq-code-input"
                type="text"
                wire:model="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                required
                autofocus
            >
            @error('code') <p class="rq-error">{{ $message }}</p> @enderror

            <button type="submit" class="rq-btn rq-btn--primary" style="width:100%;margin-top:20px" wire:loading.attr="disabled">
                {{ __('admin.login.verify') }}
            </button>
        </form>
    @endif
</div>
