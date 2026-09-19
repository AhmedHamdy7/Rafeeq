<?php

namespace Database\Factories;

use App\Domains\Geo\Models\Corridor;
use App\Domains\Safety\Models\EscortWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscortWindow>
 */
class EscortWindowFactory extends Factory
{
    protected $model = EscortWindow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corridor_id' => Corridor::factory(),
            'starts_at' => now()->setTime(21, 0),
            'ends_at' => now()->addDay()->setTime(5, 0),
            'is_auto' => true,
        ];
    }
}
