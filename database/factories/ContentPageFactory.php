<?php

namespace Database\Factories;

use App\Domains\Admin\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    protected $model = ContentPage::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->randomElement(ContentPage::KEYS),
            'body_ar' => 'واتساب من 6 الصبح لـ 10 بالليل.',
            'body_en' => 'WhatsApp, 06:00–22:00.',
            'version' => 1,
        ];
    }
}
