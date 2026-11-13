<?php

namespace Database\Factories;

use App\Domains\Admin\Enums\AnnouncementAudience;
use App\Domains\Admin\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'audience' => AnnouncementAudience::Everyone,
            'title_ar' => 'أعمال طرق على الدائري',
            'title_en' => 'Ring road works',
            'body_ar' => 'قد تتأخر الرحلات صباحًا. ننصح بالخروج مبكرًا 10 دقائق.',
            'body_en' => 'Morning trips may run late. Leave 10 minutes earlier.',
        ];
    }
}
