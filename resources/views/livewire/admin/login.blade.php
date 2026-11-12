{{--
    Staff sign-in, screens S01–S04 and S08 of the dashboard design.

    One card at a time: signed out (S08) → email and password (S01, S02) → the code (S04),
    or the lock (S03) when the limiter says so. Step two REPLACES step one rather than
    appearing beneath it, so there is never a password field on screen next to a code field.
--}}
<div>
    @if ($lockedFor !== null)
        {{-- S03. The countdown is display only; the lock is the rate limiter on the server. --}}
        <div
            x-data="{
                left: {{ (int) $lockedFor }},
                get clock() { return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0') },
                init() {
                    const timer = setInterval(() => {
                        this.left = Math.max(0, this.left - 1);
                        if (this.left === 0) { clearInterval(timer); $wire.unlock(); }
                    }, 1000);
                },
            }"
        >
            <h1 class="ad-auth__title">{{ __('admin/auth.locked.title') }}</h1>
            <p class="ad-auth__sub">
                {{ __('admin/auth.locked.text') }}
                <span class="ad-num" x-text="clock">{{ intdiv($lockedFor, 60) }}:{{ str_pad((string) ($lockedFor % 60), 2, '0', STR_PAD_LEFT) }}</span>
            </p>
            <p class="ad-auth__clock ad-num" x-text="clock" aria-hidden="true">{{ intdiv($lockedFor, 60) }}:{{ str_pad((string) ($lockedFor % 60), 2, '0', STR_PAD_LEFT) }}</p>
            <p class="ad-auth__note">{{ __('admin/auth.locked.note') }}</p>
        </div>

    @elseif ($pendingAdminId === null && session('admin_signed_out') && $email === '')
        {{-- S08. Shown once, after the idle sign-out; "Sign in again" brings the form. --}}
        <div x-data="{ again: false }">
            <template x-if="! again">
                <div>
                    <h1 class="ad-auth__title">{{ __('admin/auth.signed_out.title') }}</h1>
                    <p class="ad-auth__sub">{{ __('admin/auth.signed_out.text', ['minutes' => config('rafeeq.admin.session_idle_minutes')]) }}</p>
                    <button type="button" class="ad-btn ad-btn--primary ad-btn--block" x-on:click="again = true">
                        {{ __('admin/auth.signed_out.again') }}
                    </button>
                </div>
            </template>
            <template x-if="again">
                <div>@include('livewire.admin.partials.login-password')</div>
            </template>
        </div>

    @elseif ($pendingAdminId === null)
        @include('livewire.admin.partials.login-password')

    @else
        {{-- S04. Six boxes for the eye; one value for the server, kept a STRING so a leading zero survives. --}}
        <form wire:submit="submitCode">
            <h1 class="ad-auth__title">{{ __('admin/auth.code.title') }}</h1>
            <p class="ad-auth__sub">{{ __('admin/auth.code.text') }}</p>

            <div
                class="ad-otp"
                dir="ltr"
                x-data="{
                    digits: ['', '', '', '', '', ''],
                    sync() { $wire.code = this.digits.join('') },
                    put(i, value) {
                        const clean = value.replace(/\D/g, '');
                        if (clean.length > 1) { this.fill(clean, i); return; }
                        this.digits[i] = clean;
                        this.sync();
                        if (clean && i < 5) this.$refs['d' + (i + 1)].focus();
                        if (this.digits.join('').length === 6) $wire.submitCode();
                    },
                    fill(text, from = 0) {
                        text.replace(/\D/g, '').slice(0, 6 - from).split('').forEach((d, k) => this.digits[from + k] = d);
                        this.sync();
                        const next = Math.min(this.digits.join('').length, 5);
                        this.$refs['d' + next].focus();
                        if (this.digits.join('').length === 6) $wire.submitCode();
                    },
                    back(i, event) {
                        if (this.digits[i] === '' && i > 0) { event.preventDefault(); this.digits[i - 1] = ''; this.sync(); this.$refs['d' + (i - 1)].focus(); }
                    },
                }"
                x-on:paste.prevent="fill(($event.clipboardData || window.clipboardData).getData('text'))"
                role="group"
                aria-label="{{ __('admin/auth.code.label') }}"
            >
                @for ($i = 0; $i < 6; $i++)
                    <input
                        x-ref="d{{ $i }}"
                        class="ad-otp__box"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        @if ($i === 0) autocomplete="one-time-code" autofocus @else autocomplete="off" @endif
                        aria-label="{{ __('admin/auth.code.digit', ['n' => $i + 1]) }}"
                        x-bind:value="digits[{{ $i }}]"
                        x-on:input="put({{ $i }}, $event.target.value); $event.target.value = digits[{{ $i }}]"
                        x-on:keydown.backspace="back({{ $i }}, $event)"
                        @error('code') aria-invalid="true" @enderror
                    >
                @endfor
            </div>
            {{-- Without JavaScript (and for tests) the value still has somewhere to go. --}}
            <noscript>
                <input class="ad-input" type="text" name="code" wire:model="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            </noscript>

            @error('code') <p class="ad-error" role="alert" style="text-align:center">{{ $message }}</p> @enderror

            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" style="margin-block-start:16px" wire:loading.attr="disabled">
                {{ __('admin/auth.code.verify') }}
            </button>
            <p class="ad-auth__note">{{ __('admin/auth.code.lost') }}</p>
        </form>
    @endif
</div>
