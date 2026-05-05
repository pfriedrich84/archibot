<?php

namespace Database\Factories;

use App\Models\WorkerJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerJob>
 */
class WorkerJobFactory extends Factory
{
    protected $model = WorkerJob::class;

    public function definition(): array
    {
        return [
            'type' => WorkerJob::TYPE_POLL,
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => ['mode' => 'inbox'],
        ];
    }
}
