<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyCertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'classification',
            'standard' => null,
            'number' => 'PKT-'.fake()->unique()->numberBetween(100, 999),
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'path' => null,
            'original' => null,
            'replaced_at' => null,
        ];
    }
}
