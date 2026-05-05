<?php

namespace Database\Factories;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<McpToken> */
class McpTokenFactory extends Factory
{
    protected $model = McpToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => McpToken::hashToken(McpToken::generatePlainTextToken()),
        ];
    }
}
