<?php

namespace App\Console\Commands;

use App\Domains\Admin\Actions\ArmEscortAction;
use Illuminate\Console\Command;

final class ArmEscortTonight extends Command
{
    protected $signature = 'escort:arm-tonight';

    protected $description = "Arm tonight's night-escort window on every corridor, if night escort is enabled.";

    public function handle(ArmEscortAction $action): int
    {
        $armed = $action->armTonight();

        $this->info("Armed escort on {$armed} corridors.");

        return self::SUCCESS;
    }
}
