<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutBatchStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_no'     => 'BATCH-' . now()->format('Ym') . '-' . str_pad((string) $this->faker->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status'       => PayoutBatchStatus::Draft,
            'total_amount' => $this->faker->randomFloat(2, 10000, 5000000),
            'payout_count' => $this->faker->numberBetween(1, 20),
            'note'         => null,
            'created_by'   => User::factory(),
        ];
    }

    public function finalized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'       => PayoutBatchStatus::Finalized,
            'finalized_at' => now(),
            'finalized_by' => $attributes['created_by'],
        ]);
    }

    public function exported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'      => PayoutBatchStatus::Exported,
            'exported_at' => now(),
            'exported_by' => $attributes['created_by'],
        ]);
    }
}
