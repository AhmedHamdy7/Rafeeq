<?php

namespace Database\Factories;

use App\Domains\Admin\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSetting>
 */
class PlatformSettingFactory extends Factory
{
    protected $model = PlatformSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'setting_key' => 'trip.grace_period_seconds',
            'setting_value' => 300,
            'value_type' => 'integer',
            'description' => 'مهلة انتظار السائقة عند نقطة الالتقاء',
        ];
    }
}
