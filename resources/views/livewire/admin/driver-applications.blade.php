<div>
    <h1 style="margin:0 0 4px;font-size:19px">{{ __('admin.drivers.title') }}</h1>
    <p style="margin:0 0 20px;font-size:13.5px;color:#6b6459">
        {{ trans_choice('admin.drivers.waiting', $applications->count(), ['count' => $applications->count()]) }}
    </p>

    @error('queue') <p style="margin:0 0 14px;color:#b4232a;font-size:13px">{{ $message }}</p> @enderror

    @forelse ($applications as $application)
        <article style="background:#fff;border:1px solid #e8e4dc;border-radius:14px;padding:16px;margin-bottom:12px">
            <div style="font-size:14.5px;font-weight:600">{{ $application->user->full_name }}</div>
            <div style="font-size:12.5px;color:#6b6459;margin-top:2px">
                {{ __('admin.drivers.licence_until', ['date' => $application->licence_expiry?->toDateString() ?? '—']) }}
            </div>

            <ul style="margin:11px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:4px;font-size:13px">
                @foreach ($application->vehicles as $vehicle)
                    <li style="color:#6b6459">
                        {{ $vehicle->make }} {{ $vehicle->model }} &middot; {{ $vehicle->colour }} &middot;
                        {{ trans_choice('admin.drivers.seats', $vehicle->seats, ['count' => $vehicle->seats]) }}
                    </li>
                @endforeach
            </ul>

            @if ($mayDecide)
                <div style="display:flex;gap:9px;margin-top:15px;flex-wrap:wrap">
                    <button type="button" wire:click="approve('{{ $application->user_id }}')" wire:loading.attr="disabled"
                            style="padding:9px 16px;border:none;border-radius:999px;background:#1d6b4a;color:#fff;font-size:13px;font-weight:600;cursor:pointer">
                        {{ __('admin.drivers.approve') }}
                    </button>
                    <button type="button" wire:click="$set('openId', '{{ $application->user_id }}')"
                            style="padding:9px 16px;border:1px solid #ddd8ce;border-radius:999px;background:#faf9f5;font-size:13px;font-weight:600;cursor:pointer">
                        {{ __('admin.drivers.reject') }}
                    </button>
                </div>

                @if ($openId === $application->user_id)
                    <form wire:submit="reject('{{ $application->user_id }}')" style="margin-top:13px">
                        <textarea wire:model="reason" rows="3" required
                                  placeholder="{{ __('admin.queue.reason_placeholder') }}"
                                  style="width:100%;padding:10px 12px;border:1px solid #ddd8ce;border-radius:9px;font-size:13.5px;font-family:inherit"></textarea>
                        @error('reason') <p style="margin:6px 0 0;color:#b4232a;font-size:12.5px">{{ $message }}</p> @enderror
                        <button type="submit" style="margin-top:9px;padding:9px 16px;border:none;border-radius:999px;background:#b4232a;color:#fff;font-size:13px;font-weight:600;cursor:pointer">
                            {{ __('admin.drivers.confirm_reject') }}
                        </button>
                    </form>
                @endif
            @else
                <p style="margin:14px 0 0;font-size:12.5px;color:#8b857a">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p style="padding:26px;text-align:center;color:#8b857a;font-size:13.5px;background:#fff;border:1px solid #e8e4dc;border-radius:14px">
            {{ __('admin.drivers.empty') }}
        </p>
    @endforelse
</div>
