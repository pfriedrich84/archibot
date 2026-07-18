<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReviewSuggestionController;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Support\OperatorPrincipal;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class CommitReviewSuggestion extends Command
{
    protected $signature = 'archibot:review-commit
        {suggestion-id : Laravel review_suggestions id}
        {--user-id= : Explicit ArchiBot/Paperless user performing the review decision}';

    protected $description = 'Accept and queue a review commit through the same Laravel action used by the Review UI.';

    public function handle(ReviewSuggestionController $reviews): int
    {
        $rawId = $this->argument('suggestion-id');
        $suggestionId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($suggestionId === false) {
            $this->error('The suggestion-id must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $rawUserId = $this->option('user-id');
            $userId = filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new RuntimeException('The --user-id option is required and must identify the review operator.');
            }
            $user = User::query()->findOrFail($userId);
            $suggestion = ReviewSuggestion::query()->findOrFail($suggestionId);
            $request = Request::create("/cli/archibot/review/{$suggestionId}/accept", 'POST');
            $request->setUserResolver(fn (): User => $user);
            OperatorPrincipal::markLocalOperator($request);
            $request->headers->set('User-Agent', 'archibot-local-operator');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            $reviews->accept($request, $suggestion);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $suggestion->refresh();
        $this->info("Review suggestion {$suggestion->id} accepted; durable command {$suggestion->commit_command_id} queued through Laravel.");

        return self::SUCCESS;
    }
}
