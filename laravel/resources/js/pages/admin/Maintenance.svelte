<script module lang="ts">
    import { index as maintenanceIndex } from '@/routes/admin/maintenance';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin maintenance',
                href: maintenanceIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { recoverWorkerJobs, workerJobs } from '@/routes/admin/maintenance';

    type AuditLog = {
        id: number;
        event: string;
        target_type: string | null;
        target_id: string | null;
        metadata: Record<string, unknown>;
        created_at: string | null;
    };

    let {
        workerJobCounts,
        recentAuditLogs,
    }: {
        workerJobCounts: {
            queued: number;
            running: number;
            cancelling: number;
            failed: number;
        };
        recentAuditLogs: AuditLog[];
    } = $props();

    const jobActions = [
        {
            label: 'Start poll reconciliation',
            type: 'poll',
            force: false,
            description: 'Run the inbox polling/reconciliation worker now.',
        },
        {
            label: 'Start full reindex',
            type: 'reindex',
            force: false,
            description: 'Queue a full document reindex worker job.',
        },
        {
            label: 'Start OCR reindex',
            type: 'reindex_ocr',
            force: false,
            description: 'Queue OCR reindex without force.',
        },
        {
            label: 'Start OCR reindex force',
            type: 'reindex_ocr',
            force: true,
            description: 'Queue OCR reindex and force OCR refresh.',
        },
        {
            label: 'Start embedding reindex',
            type: 'reindex_embed',
            force: false,
            description: 'Queue embedding reindex worker job.',
        },
    ];
</script>

<AppHead title="Admin maintenance" />

<div class="space-y-6">
    <Heading
        title="Admin maintenance"
        description="Admin-only recovery and reindex controls. Destructive reset is CLI-only for operators."
    />

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Worker status</h2>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-sm text-muted-foreground">Queued</dt>
                <dd class="text-2xl font-semibold">{workerJobCounts.queued}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Running</dt>
                <dd class="text-2xl font-semibold">
                    {workerJobCounts.running}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Cancelling</dt>
                <dd class="text-2xl font-semibold">
                    {workerJobCounts.cancelling}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Failed</dt>
                <dd class="text-2xl font-semibold">{workerJobCounts.failed}</dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Recovery</h2>
        <p class="mb-4 text-sm text-muted-foreground">
            Redispatch stale queued jobs, recover stale running jobs and close
            stale cancelling jobs.
        </p>
        <Form method="post" action={recoverWorkerJobs().url}>
            {#snippet children({ processing })}
                <Button type="submit" disabled={processing}
                    >Run worker recovery now</Button
                >
            {/snippet}
        </Form>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Maintenance worker jobs</h2>
        <div class="grid gap-3 lg:grid-cols-2">
            {#each jobActions as action (action.label)}
                <Form
                    method="post"
                    action={workerJobs().url}
                    class="rounded-lg border p-3"
                >
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value={action.type} />
                        {#if action.force}
                            <input type="hidden" name="force" value="1" />
                        {/if}
                        <p class="font-medium">{action.label}</p>
                        <p class="mb-3 text-sm text-muted-foreground">
                            {action.description}
                        </p>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Queue job
                        </Button>
                    {/snippet}
                </Form>
            {/each}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Recent maintenance audit logs</h2>
        {#if recentAuditLogs.length === 0}
            <p class="text-sm text-muted-foreground">
                No maintenance audit logs yet.
            </p>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2 pr-3">Time</th>
                            <th class="py-2 pr-3">Event</th>
                            <th class="py-2 pr-3">Target</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each recentAuditLogs as log (log.id)}
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-3"
                                    >{log.created_at ?? '-'}</td
                                >
                                <td class="py-2 pr-3 font-mono">{log.event}</td>
                                <td class="py-2 pr-3">
                                    {log.target_type ?? '-'}:{log.target_id ??
                                        '-'}
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>
</div>
