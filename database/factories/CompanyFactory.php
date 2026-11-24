<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Every column, nullable ones included — see UserFactory for why.
     *
     * `user_id` creates its own account because the column is NOT NULL and
     * unique: one user owns exactly one company, so a factory that shared an
     * account between two companies would hit the index rather than the rule.
     *
     * `state` is the office ZONE this company is administered under — the string
     * a state admin is scoped by, from AdminZones. It is not an address; there is
     * no address here at all.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'registration_no' => null,
            'state' => 'Johor',
            'business_type' => 'Pengeluar atau Pedagang',
            'kraf_code' => null,
            'plot_size' => null,
            'credentials' => null,
            'workshop_address' => null,
            'office_phone' => null,
            'fax' => null,
            'facebook' => null,
            'instagram' => null,
            'website' => null,
            'is_active' => true,
        ];
    }

    /** A profile complete enough to join: no recognition or certificate, one staff member to contact. */
    public function withProfile(): static
    {
        return $this->state(fn () => ['credentials' => [
            'recognition' => 'none', 'classification' => 'no', 'quality' => null,
            'entrepreneur_category' => 'Mikro, Kecil & Sederhana (MKS)', 'oku' => 'no',
            'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']], 'product_images' => [],
        ]]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
