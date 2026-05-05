<?php

namespace Database\Factories;

use App\Models\EntityApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EntityApproval> */
class EntityApprovalFactory extends Factory
{
    protected $model = EntityApproval::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => EntityApproval::TYPE_TAG,
            'name' => fake()->unique()->words(2, true),
            'status' => EntityApproval::STATUS_PENDING,
        ];
    }
}
