<?php

use App\Domains\Admin\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $stored = PlatformSetting::query()->find('commute.schedule_lock_hours');

        if ($stored !== null && (int) $stored->setting_value === 16) {
            $stored->delete();
        }
    }

    public function down(): void {}
};
