<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\SalesBookTab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesBookTab>
 */
class SalesBookTabFactory extends Factory
{
    protected $model = SalesBookTab::class;

    /** Every column, nullable ones included — see UserFactory for why. */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => strtoupper(fake()->unique()->lexify('PROGRAM ????')),
            'blocks' => 10,
        ];
    }
}
