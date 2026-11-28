<?php

namespace Database\Factories;

use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Models\AppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppRelease>
 */
class AppReleaseFactory extends Factory
{
    protected $model = AppRelease::class;

    public function definition(): array
    {
        return [
            'platform' => AppPlatform::Android,
            'latest_version' => '1.4.0',
            'minimum_version' => '1.2.0',
            'store_url' => 'https://play.google.com/store/apps/details?id=com.yugo.app',
            'force_update' => false,
        ];
    }

    public function ios(): static
    {
        return $this->state(fn () => [
            'platform' => AppPlatform::Ios,
            'store_url' => 'https://apps.apple.com/app/id0000000000',
        ]);
    }
}
