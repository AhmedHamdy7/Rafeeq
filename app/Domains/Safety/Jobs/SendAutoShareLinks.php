<?php

namespace App\Domains\Safety\Jobs;

use App\Domains\Safety\Actions\AutoShareTripAction;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendAutoShareLinks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $tripSessionId) {}

    public function handle(AutoShareTripAction $action): void
    {
        $session = TripSession::query()->whereKey($this->tripSessionId)->first();

        if ($session !== null) {
            $action->forRun($session);
        }
    }
}
