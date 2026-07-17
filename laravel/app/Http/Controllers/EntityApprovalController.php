<?php

namespace App\Http\Controllers;

use App\Jobs\RunPythonActorJob;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\PipelineEvent;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EntityApprovalController extends Controller
{
    /** @var array<string, array{type: string, title: string}> */
    private const SEGMENTS = [
        'tags' => ['type' => EntityApproval::TYPE_TAG, 'title' => 'Tags'],
        'correspondents' => ['type' => EntityApproval::TYPE_CORRESPONDENT, 'title' => 'Correspondents'],
        'doctypes' => ['type' => EntityApproval::TYPE_DOCUMENT_TYPE, 'title' => 'Document types'],
    ];

    public function index(Request $request, string $segment): Response
    {
        $meta = $this->meta($segment);

        return Inertia::render('entities/Index', [
            'segment' => $segment,
            'type' => $meta['type'],
            'title' => $meta['title'],
            'isAdmin' => (bool) $request->user()->is_admin,
            'pending' => $this->items($meta['type'], EntityApproval::STATUS_PENDING),
            'approved' => $this->items($meta['type'], EntityApproval::STATUS_APPROVED),
            'rejected' => $this->items($meta['type'], EntityApproval::STATUS_REJECTED),
        ]);
    }

    public function approve(Request $request, string $segment, EntityApproval $entityApproval): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless($entityApproval->status === EntityApproval::STATUS_PENDING, 409);

        $token = $request->user()->paperless_token;
        abort_if(! $token, 503, 'Paperless connection is not available.');

        $client = new PaperlessClient;
        $paperlessId = match ($entityApproval->type) {
            EntityApproval::TYPE_TAG => $client->createTag($token, $entityApproval->name),
            EntityApproval::TYPE_CORRESPONDENT => $client->createCorrespondent($token, $entityApproval->name),
            EntityApproval::TYPE_DOCUMENT_TYPE => $client->createDocumentType($token, $entityApproval->name),
            default => abort(404),
        };

        $entityApproval->mark(EntityApproval::STATUS_APPROVED, $request->user(), $paperlessId);
        $this->audit($request, $entityApproval, 'approved', ['paperless_id' => $paperlessId]);
        $this->queueSync($request, $entityApproval, 'approved');

        return back();
    }

    public function reject(Request $request, string $segment, EntityApproval $entityApproval): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless($entityApproval->status === EntityApproval::STATUS_PENDING, 409);

        $entityApproval->mark(EntityApproval::STATUS_REJECTED, $request->user());
        $this->audit($request, $entityApproval, 'rejected');
        $this->queueSync($request, $entityApproval, 'rejected');

        return back();
    }

    public function unblacklist(Request $request, string $segment, EntityApproval $entityApproval): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless($entityApproval->status === EntityApproval::STATUS_REJECTED, 409);

        $entityApproval->mark(EntityApproval::STATUS_PENDING, $request->user());
        $this->audit($request, $entityApproval, 'unblacklisted');
        $this->queueSync($request, $entityApproval, 'unblacklisted');

        return back();
    }

    /** @return array<int, array<string, mixed>> */
    private function items(string $type, string $status): array
    {
        return EntityApproval::query()
            ->where('type', $type)
            ->where('status', $status)
            ->latest()
            ->get()
            ->map(fn (EntityApproval $entity) => [
                'id' => $entity->id,
                'type' => $entity->type,
                'name' => $entity->name,
                'status' => $entity->status,
                'paperless_id' => $entity->paperless_id,
                'source_review_suggestion_id' => $entity->source_review_suggestion_id,
                'sync_status' => $entity->sync_status,
                'created_at' => $entity->created_at?->toISOString(),
            ])
            ->all();
    }

    /** @return array{type: string, title: string} */
    private function meta(string $segment): array
    {
        abort_unless(array_key_exists($segment, self::SEGMENTS), 404);

        return self::SEGMENTS[$segment];
    }

    private function ensureSegmentMatches(string $segment, EntityApproval $entityApproval): void
    {
        abort_unless($this->meta($segment)['type'] === $entityApproval->type, 404);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()->is_admin, 403);
    }

    private function queueSync(Request $request, EntityApproval $entityApproval, string $action): void
    {
        $payload = [
            'entity_approval_id' => $entityApproval->id,
            'action' => $action,
            'type' => $entityApproval->type,
            'name' => $entityApproval->name,
            'paperless_id' => $entityApproval->paperless_id,
        ];

        $command = Command::query()->create([
            'type' => Command::TYPE_SYNC_ENTITY_APPROVAL,
            'status' => Command::STATUS_PENDING,
            'payload' => $payload,
            'created_by_user_id' => $request->user()->id,
        ]);

        PipelineEvent::query()->create([
            'command_id' => $command->id,
            'event_type' => 'job_control.entity_approval_sync_requested',
            'level' => 'info',
            'message' => 'Entity approval sync requested by admin.',
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'command_id' => $command->id,
                ...$payload,
            ],
        ]);

        DB::transaction(function () use ($command, $entityApproval): void {
            $command = Command::query()->lockForUpdate()->findOrFail($command->id);
            $entityApproval = EntityApproval::query()->lockForUpdate()->findOrFail($entityApproval->id);
            if ($command->status !== Command::STATUS_PENDING) {
                return;
            }

            $command->forceFill(['status' => Command::STATUS_QUEUED])->save();
            $entityApproval->forceFill([
                'sync_status' => EntityApproval::SYNC_STATUS_QUEUED,
            ])->save();
            dispatch(RunPythonActorJob::syncEntityApproval($command->id));
        });
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, EntityApproval $entityApproval, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => "entity_approval.{$action}",
            'target_type' => 'entity_approval',
            'target_id' => (string) $entityApproval->id,
            'metadata' => [
                'type' => $entityApproval->type,
                'name' => $entityApproval->name,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
