<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PaperlessMasterDataCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaperlessMasterDataCaseController extends Controller
{
    /** @var array<string, string> */
    private const SEGMENTS = [
        'tags' => 'tag',
        'correspondents' => 'correspondent',
        'doctypes' => 'document_type',
    ];

    public function index(Request $request, string $segment): Response
    {
        $type = $this->typeFor($segment);

        return Inertia::render('entities/Index', [
            'segment' => $segment,
            'type' => $type,
            'title' => $this->titleFor($type),
            'isAdmin' => (bool) $request->user()->is_admin,
            'pending' => $this->items($type, PaperlessMasterDataCase::STATUS_PENDING),
            'approved' => $this->items($type, PaperlessMasterDataCase::STATUS_APPROVED),
            'rejected' => $this->items($type, PaperlessMasterDataCase::STATUS_REJECTED),
        ]);
    }

    public function approve(Request $request, string $segment, PaperlessMasterDataCase $paperlessMasterDataCase): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->typeFor($segment) === $paperlessMasterDataCase->entity_type, 404);
        abort_unless($paperlessMasterDataCase->status === PaperlessMasterDataCase::STATUS_PENDING, 409);

        $paperlessMasterDataCase->forceFill([
            'status' => PaperlessMasterDataCase::STATUS_APPROVED,
            'sync_status' => PaperlessMasterDataCase::SYNC_STATUS_QUEUED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, $paperlessMasterDataCase, 'approved', ['outcome' => 'queued']);

        return back()->with('status', "Approval for '{$paperlessMasterDataCase->canonical_name}' was queued.");
    }

    public function reject(Request $request, string $segment, PaperlessMasterDataCase $paperlessMasterDataCase): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->typeFor($segment) === $paperlessMasterDataCase->entity_type, 404);
        abort_unless($paperlessMasterDataCase->status === PaperlessMasterDataCase::STATUS_PENDING, 409);

        $paperlessMasterDataCase->forceFill([
            'status' => PaperlessMasterDataCase::STATUS_REJECTED,
            'sync_status' => PaperlessMasterDataCase::SYNC_STATUS_SYNCED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, $paperlessMasterDataCase, 'rejected', ['outcome' => 'queued']);

        return back()->with('status', "Rejection of '{$paperlessMasterDataCase->canonical_name}' was queued.");
    }

    public function unblacklist(Request $request, string $segment, PaperlessMasterDataCase $paperlessMasterDataCase): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->typeFor($segment) === $paperlessMasterDataCase->entity_type, 404);
        abort_unless(in_array($paperlessMasterDataCase->status, [PaperlessMasterDataCase::STATUS_REJECTED, PaperlessMasterDataCase::STATUS_SUPPRESSED], true), 409);

        $paperlessMasterDataCase->forceFill([
            'status' => PaperlessMasterDataCase::STATUS_PENDING,
            'sync_status' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'suppressed_until' => null,
        ])->save();

        $this->audit($request, $paperlessMasterDataCase, 'unblacklisted', ['outcome' => 'queued']);

        return back()->with('status', "Blocklist removal for '{$paperlessMasterDataCase->canonical_name}' was queued.");
    }

    /** @return array<int, array<string, mixed>> */
    private function items(string $type, string $status): array
    {
        return PaperlessMasterDataCase::query()
            ->where('entity_type', $type)
            ->where('status', $status)
            ->latest()
            ->get()
            ->map(fn (PaperlessMasterDataCase $entity) => [
                'id' => $entity->id,
                'type' => $entity->entity_type,
                'name' => $entity->canonical_name ?: $entity->normalized_name,
                'status' => $entity->status,
                'paperless_id' => $entity->mapped_paperless_id,
                'source_review_suggestion_id' => null,
                'sync_status' => $entity->sync_status,
                'created_at' => $entity->created_at?->toISOString(),
            ])
            ->all();
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()->is_admin, 403);
    }

    private function typeFor(string $segment): string
    {
        abort_unless(array_key_exists($segment, self::SEGMENTS), 404);

        return self::SEGMENTS[$segment];
    }

    private function titleFor(string $type): string
    {
        return match ($type) {
            'tag' => 'Tags',
            'correspondent' => 'Correspondents',
            'document_type' => 'Document types',
            default => 'Master data',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, PaperlessMasterDataCase $entity, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => "paperless_master_data_case.{$action}",
            'target_type' => 'paperless_master_data_case',
            'target_id' => (string) $entity->id,
            'metadata' => [
                'actor_principal' => 'authenticated_user',
                'type' => $entity->entity_type,
                'name' => $entity->canonical_name ?: $entity->normalized_name,
                ...$metadata,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
