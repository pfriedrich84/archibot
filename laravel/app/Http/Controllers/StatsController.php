<?php

namespace App\Http\Controllers;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\WebhookDelivery;
use App\Services\LegacyPythonState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function __invoke(LegacyPythonState $legacyPythonState): Response
    {
        return Inertia::render('stats/Index', [
            'review' => [
                'pending' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_PENDING)->count(),
                'accepted' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_ACCEPTED)->count(),
                'rejected' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_REJECTED)->count(),
            ],
            'reviewStatusCounts' => $this->countsBy(ReviewSuggestion::class, 'status'),
            'reviewConfidenceDistribution' => $this->reviewConfidenceDistribution(),
            'reviewJudgeCounts' => $this->countsBy(ReviewSuggestion::class, 'judge_verdict'),
            'entities' => [
                'pending' => EntityApproval::query()->where('status', EntityApproval::STATUS_PENDING)->count(),
                'approved' => EntityApproval::query()->where('status', EntityApproval::STATUS_APPROVED)->count(),
                'rejected' => EntityApproval::query()->where('status', EntityApproval::STATUS_REJECTED)->count(),
            ],
            'entityApprovalMatrix' => $this->matrixCounts(EntityApproval::class, 'type', 'status'),
            'webhookStatusCounts' => $this->countsBy(WebhookDelivery::class, 'status'),
            'pipelineRunStatusCounts' => $this->countsBy(PipelineRun::class, 'status'),
            'pipelineRunTypeMatrix' => $this->matrixCounts(PipelineRun::class, 'type', 'status'),
            'actorStatusCounts' => $this->countsBy(ActorExecution::class, 'status'),
            'actorNameMatrix' => $this->matrixCounts(ActorExecution::class, 'actor_name', 'status'),
            'dailyActivity' => $this->dailyActivity(),
            'python' => $legacyPythonState->stats(),
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, int>
     */
    private function countsBy(string $modelClass, string $column): array
    {
        return $modelClass::query()
            ->selectRaw($column.', count(*) as aggregate')
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, array<string, int>>
     */
    private function matrixCounts(string $modelClass, string $rowColumn, string $statusColumn): array
    {
        return $modelClass::query()
            ->selectRaw($rowColumn.', '.$statusColumn.', count(*) as aggregate')
            ->whereNotNull($rowColumn)
            ->whereNotNull($statusColumn)
            ->groupBy($rowColumn, $statusColumn)
            ->orderBy($rowColumn)
            ->orderBy($statusColumn)
            ->get()
            ->reduce(function (array $matrix, Model $row) use ($rowColumn, $statusColumn): array {
                $rowKey = (string) $row->getAttribute($rowColumn);
                $statusKey = (string) $row->getAttribute($statusColumn);
                $matrix[$rowKey][$statusKey] = (int) $row->getAttribute('aggregate');

                return $matrix;
            }, []);
    }

    /** @return array<string, int> */
    private function reviewConfidenceDistribution(): array
    {
        $buckets = [
            '0-49' => 0,
            '50-69' => 0,
            '70-84' => 0,
            '85-100' => 0,
            'unknown' => 0,
        ];

        ReviewSuggestion::query()
            ->select('confidence')
            ->get()
            ->each(function (ReviewSuggestion $suggestion) use (&$buckets): void {
                $confidence = $suggestion->confidence;

                if (! is_numeric($confidence)) {
                    $buckets['unknown']++;
                } elseif ($confidence < 50) {
                    $buckets['0-49']++;
                } elseif ($confidence < 70) {
                    $buckets['50-69']++;
                } elseif ($confidence < 85) {
                    $buckets['70-84']++;
                } else {
                    $buckets['85-100']++;
                }
            });

        return $buckets;
    }

    /** @return array<int, array{date: string, reviews_created: int, reviews_completed: int, commands_finished: int, webhook_deliveries: int, pipeline_runs: int}> */
    private function dailyActivity(): array
    {
        $start = now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->mapWithKeys(fn (int $offset): array => [
            $start->copy()->addDays($offset)->toDateString() => [
                'date' => $start->copy()->addDays($offset)->toDateString(),
                'reviews_created' => 0,
                'reviews_completed' => 0,
                'commands_finished' => 0,
                'webhook_deliveries' => 0,
                'pipeline_runs' => 0,
            ],
        ])->all();

        $this->incrementDailyCounts($days, ReviewSuggestion::query()->where('created_at', '>=', $start)->pluck('created_at')->all(), 'reviews_created');
        $this->incrementDailyCounts($days, ReviewSuggestion::query()->where('reviewed_at', '>=', $start)->pluck('reviewed_at')->all(), 'reviews_completed');
        $this->incrementDailyCounts($days, Command::query()->where('finished_at', '>=', $start)->pluck('finished_at')->all(), 'commands_finished');
        $this->incrementDailyCounts($days, WebhookDelivery::query()->where('received_at', '>=', $start)->pluck('received_at')->all(), 'webhook_deliveries');
        $this->incrementDailyCounts($days, PipelineRun::query()->where('created_at', '>=', $start)->pluck('created_at')->all(), 'pipeline_runs');

        return array_values($days);
    }

    /**
     * @param  array<string, array<string, int|string>>  $days
     * @param  array<int, mixed>  $timestamps
     */
    private function incrementDailyCounts(array &$days, array $timestamps, string $field): void
    {
        foreach ($timestamps as $timestamp) {
            $date = $timestamp instanceof CarbonInterface
                ? $timestamp->toDateString()
                : (is_string($timestamp) ? Carbon::parse($timestamp)->toDateString() : null);

            if ($date !== null && isset($days[$date])) {
                $days[$date][$field]++;
            }
        }
    }
}
