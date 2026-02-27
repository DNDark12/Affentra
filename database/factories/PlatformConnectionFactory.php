<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlatformConnection>
 */
class PlatformConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'    => \App\Models\User::factory(),
            'platform'   => 'shopee',
            'method'     => 'open_api',
            'app_id'     => $this->faker->uuid(),
            'app_secret' => $this->faker->uuid(),
            'status'     => 'active',
            'sync_mode'  => 'manual',
        ];
    }
}
