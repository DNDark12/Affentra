<?php

namespace Database\Factories;

use App\Models\UserProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserProfile>
 */
class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_code' => $this->faker->word(),
            'bank_name' => $this->faker->company(),
            'bank_account_name' => $this->faker->name(),
            'bank_account_number' => $this->faker->bankAccountNumber(),
            'tax_id' => $this->faker->numerify('##########'),
            'is_payout_ready' => false,
        ];
    }
}
