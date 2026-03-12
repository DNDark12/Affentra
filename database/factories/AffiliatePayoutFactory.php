<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AffiliatePayout>
 */
class AffiliatePayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform_connection_id' => PlatformConnection::factory(),
            'user_id'                => User::factory(),
            'platform'               => 'shopee',
            'payout_id'              => 'PO-' . $this->faker->unique()->numerify('######'),
            'payout_at'              => now()->subDays($this->faker->numberBetween(1, 30)),
            'amount'                 => $this->faker->randomFloat(2, 10000, 500000),
            'currency'               => 'VND',
            'bank_name'              => $this->faker->randomElement(['Vietcombank', 'Techcombank', 'BIDV']),
            'account_number_masked'  => '**** **** ' . $this->faker->numerify('####'),
            'status'                 => 'paid',
        ];
    }

    public function unbatched(): static
    {
        return $this->state(fn (): array => ['payout_batch_id' => null]);
    }
}
