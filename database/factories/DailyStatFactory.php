<?php

namespace Database\Factories;

use App\Models\DailyStat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailyStat>
 */
class DailyStatFactory extends Factory
{
    protected $model = DailyStat::class;

    public function definition(): array
    {
        return [
            'date' => $this->faker->date(),
            'platform' => 'shopee',
            'user_id' => User::factory(),
            'clicks' => $this->faker->numberBetween(0, 100),
            'unique_clicks' => $this->faker->numberBetween(0, 80),
            'valid_clicks' => $this->faker->numberBetween(0, 70),
            'bot_clicks' => $this->faker->numberBetween(0, 10),
            'orders' => $this->faker->numberBetween(0, 10),
            'approved' => $this->faker->numberBetween(0, 5),
            'commission' => $this->faker->randomFloat(2, 0, 1000),
        ];
    }
}
