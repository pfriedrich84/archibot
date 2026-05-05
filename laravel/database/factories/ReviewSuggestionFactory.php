<?php

namespace Database\Factories;

use App\Models\ReviewSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewSuggestion>
 */
class ReviewSuggestionFactory extends Factory
{
    protected $model = ReviewSuggestion::class;

    public function definition(): array
    {
        return [
            'paperless_document_id' => fake()->numberBetween(1, 10000),
            'status' => ReviewSuggestion::STATUS_PENDING,
            'confidence' => fake()->numberBetween(50, 99),
            'reasoning' => fake()->sentence(),
            'original_title' => 'Original document title',
            'original_date' => '2026-01-01',
            'original_tags' => [['id' => 1, 'name' => 'Inbox']],
            'proposed_title' => 'Proposed document title',
            'proposed_date' => '2026-01-02',
            'proposed_correspondent_name' => 'Example Correspondent',
            'proposed_document_type_name' => 'Invoice',
            'proposed_tags' => [['id' => 2, 'name' => 'Archive']],
            'context_documents' => [['id' => 123, 'title' => 'Similar document']],
        ];
    }
}
