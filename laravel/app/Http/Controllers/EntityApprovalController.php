<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Services\EntityApprovalDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function approve(Request $request, string $segment, EntityApproval $entityApproval, EntityApprovalDecisionService $decisions): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless(
            $entityApproval->status === EntityApproval::STATUS_PENDING
                || ($entityApproval->status === EntityApproval::STATUS_APPROVED
                    && $entityApproval->sync_status === EntityApproval::SYNC_STATUS_FAILED),
            409,
        );

        $token = $request->user()->paperless_token;
        abort_if(! $token, 503, 'Paperless connection is not available.');

        try {
            $command = $this->enqueueDecision($decisions, $entityApproval, 'approved', $request);
        } catch (\DomainException $exception) {
            return back()->with('error', 'Entity approval could not be queued: '.$exception->getMessage());
        }
        $this->audit($request, $entityApproval, 'approved', [
            'command_id' => $command->id,
            'outcome' => 'queued',
        ]);

        return back()->with('status', "Approval for '{$entityApproval->name}' was queued.");
    }

    public function reject(Request $request, string $segment, EntityApproval $entityApproval, EntityApprovalDecisionService $decisions): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless($entityApproval->status === EntityApproval::STATUS_PENDING, 409);

        try {
            $command = $this->enqueueDecision($decisions, $entityApproval, 'rejected', $request);
        } catch (\DomainException $exception) {
            return back()->with('error', 'Entity rejection could not be queued: '.$exception->getMessage());
        }
        $this->audit($request, $entityApproval, 'rejected', ['command_id' => $command->id, 'outcome' => 'queued']);

        return back()->with('status', "Rejection of '{$entityApproval->name}' was queued.");
    }

    public function unblacklist(Request $request, string $segment, EntityApproval $entityApproval, EntityApprovalDecisionService $decisions): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureSegmentMatches($segment, $entityApproval);

        abort_unless($entityApproval->status === EntityApproval::STATUS_REJECTED, 409);

        try {
            $command = $this->enqueueDecision($decisions, $entityApproval, 'unblacklisted', $request);
        } catch (\DomainException $exception) {
            return back()->with('error', 'Blocklist removal could not be queued: '.$exception->getMessage());
        }
        $this->audit($request, $entityApproval, 'unblacklisted', ['command_id' => $command->id, 'outcome' => 'queued']);

        return back()->with('status', "Blocklist removal for '{$entityApproval->name}' was queued.");
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

    private function enqueueDecision(
        EntityApprovalDecisionService $decisions,
        EntityApproval $entityApproval,
        string $action,
        Request $request,
    ): Command {
        return $decisions->enqueue($entityApproval, $action, $request->user());
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
                'actor_principal' => 'authenticated_user',
                'type' => $entityApproval->type,
                'name' => $entityApproval->name,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
