<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Pipeline runs',
                href: '/pipeline-runs',
            },
            {
                title: 'Run detail',
                href: '#',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';

    type JsonObject = Record<string, unknown>;

    type PipelineEvent = {
        id: number;
        event_type: string;
        level: string;
        message: string | null;
        paperless_document_id: number | null;
        webhook_delivery_id: number | null;
        command_id: number | null;
        payload: JsonObject;
        created_at: string | null;
    };

    type PipelineItem = {
        id: number;
        paperless_document_id: number | null;
        item_type: string;
        status: string;
        attempt: number;
        max_attempts: number;
        retry_reason: string | null;
        retry_mode: string | null;
        next_retry_at: string | null;
        last_retry_at: string | null;
        started_at: string | null;
        finished_at: string | null;
        error: string | null;
    };

    type LinkedWorkerJob = {
        id: number;
        type: string;
        status: string;
        paperless_document_id: number | null;
        dispatch_key: string | null;
        created_at: string | null;
        updated_at: string | null;
        show_url: string;
    };

    type AuditLog = {
        id: number;
        event: string;
        target_type: string;
        target_id: string;
        metadata: JsonObject;
        created_at: string | null;
    };

    type Run = {
        id: number;
        type: string;
        status: string;
        scope: string | null;
        trigger_source: string;
        paperless_document_id: number | null;
        progress_total: number;
        progress_done: number;
        progress_failed: number;
        progress_skipped: number;
        progress_current_phase: string | null;
        progress_phase_total: number;
        progress_phase_done: number;
        progress_message: string | null;
        progress_updated_at: string | null;
        retry_count: number;
        max_retries: number;
        next_retry_at: string | null;
        last_retry_at: string | null;
        retry_reason: string | null;
        retry_mode: string | null;
        reprocess_requested: boolean;
        reprocess_reason: string | null;
        reprocess_mode: string | null;
        started_at: string | null;
        finished_at: string | null;
        created_at: string | null;
        updated_at: string | null;
        error_type: string | null;
        error: string | null;
        retry_url: string;
        retry_failed_items_url: string;
        cancel_url: string;
        can_retry: boolean;
        can_retry_failed_items: boolean;
        can_cancel: boolean;
        command: {
            id: number;
            type: string;
            status: string;
            payload?: JsonObject;
            created_by_user_id?: number | null;
            started_at?: string | null;
            finished_at?: string | null;
            error?: string | null;
            created_at: string | null;
        } | null;
        webhook_delivery: {
            id: number;
            source: string;
            event_type: string;
            status: string;
            paperless_document_id: number | null;
            show_url: string;
            dedupe_key?: string | null;
            request_id?: string | null;
            received_at?: string | null;
            processed_at?: string | null;
            error?: string | null;
        } | null;
        events: PipelineEvent[];
        items: PipelineItem[];
        linked_worker_jobs: LinkedWorkerJob[];
        audit_logs: AuditLog[];
    };

    let {
        run,
        isAdmin,
    }: {
        run: Run;
        isAdmin: boolean;
    } = $props();

    const pretty = (value: unknown) => JSON.stringify(value, null, 2);
</script>

<AppHead title={`Pipeline run ${run.id}`} />

<div class="space-y-6">
    <Heading
        title={`Pipeline run ${run.id}`}
        description={`${run.type} · ${run.trigger_source}`}
    />

    <section class="rounded-xl border p-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-muted px-2 py-0.5 text-sm">
                {run.status}
            </span>
            {#if run.scope}<span class="text-sm text-muted-foreground"
                    >scope {run.scope}</span
                >{/if}
            {#if run.paperless_document_id}<span
                    class="text-sm text-muted-foreground"
                    >document {run.paperless_document_id}</span
                >{/if}
            {#if isAdmin && (run.can_retry || run.can_retry_failed_items || run.can_cancel)}
                <div class="ml-auto flex flex-wrap gap-2">
                    {#if run.can_retry}
                        <Form method="post" action={run.retry_url}>
                            {#snippet children({ processing })}
                                <button
                                    type="submit"
                                    class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                    disabled={processing}>Retry run</button
                                >
                            {/snippet}
                        </Form>
                    {/if}
                    {#if run.can_retry_failed_items}
                        <Form method="post" action={run.retry_failed_items_url}>
                            {#snippet children({ processing })}
                                <button
                                    type="submit"
                                    class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                    disabled={processing}
                                    >Retry failed items</button
                                >
                            {/snippet}
                        </Form>
                    {/if}
                    {#if run.can_cancel}
                        <Form method="post" action={run.cancel_url}>
                            {#snippet children({ processing })}
                                <button
                                    type="submit"
                                    class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                    disabled={processing}>Cancel run</button
                                >
                            {/snippet}
                        </Form>
                    {/if}
                </div>
            {/if}
        </div>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Trigger source</dt>
                <dd class="font-medium">{run.trigger_source}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Progress</dt>
                <dd class="font-medium">
                    {run.progress_done}/{run.progress_total} done · {run.progress_failed}
                    failed · {run.progress_skipped} skipped
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Phase</dt>
                <dd class="font-medium">
                    {run.progress_current_phase ?? '—'} ({run.progress_phase_done}/{run.progress_phase_total})
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Progress updated</dt>
                <dd class="font-medium">{run.progress_updated_at ?? '—'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Created</dt>
                <dd class="font-medium">{formatDateTime(run.created_at)}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Updated</dt>
                <dd class="font-medium">{run.updated_at ?? '—'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Started</dt>
                <dd class="font-medium">{formatDateTime(run.started_at)}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Finished</dt>
                <dd class="font-medium">{formatDateTime(run.finished_at)}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Retry</dt>
                <dd class="font-medium">
                    {run.retry_count}/{run.max_retries} · {run.retry_mode ??
                        '—'} · {run.retry_reason ?? '—'}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Next retry</dt>
                <dd class="font-medium">{run.next_retry_at ?? '—'}</dd>
            </div>
        </dl>

        {#if run.progress_message}
            <div
                class="mt-4 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                {run.progress_message}
            </div>
        {/if}
        {#if run.error}
            <div
                class="mt-4 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
            >
                {run.error_type ?? 'error'}: {run.error}
            </div>
        {/if}
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Linked command</h2>
            {#if run.command}
                <dl class="grid gap-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">ID</dt>
                        <dd class="font-medium">{run.command.id}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Type/status</dt>
                        <dd class="font-medium">
                            {run.command.type} · {run.command.status}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Created by</dt>
                        <dd class="font-medium">
                            {run.command.created_by_user_id ?? '—'}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Error</dt>
                        <dd class="font-medium">{run.command.error ?? '—'}</dd>
                    </div>
                </dl>
                <pre class="mt-3 overflow-x-auto text-xs">{pretty(
                        run.command.payload ?? {},
                    )}</pre>
            {:else}
                <div class="text-sm text-muted-foreground">
                    No command is linked to this run yet.
                </div>
            {/if}
        </div>

        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Linked webhook delivery</h2>
            {#if run.webhook_delivery}
                <dl class="grid gap-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Delivery</dt>
                        <dd class="font-medium">
                            <a
                                class="underline-offset-4 hover:underline"
                                href={run.webhook_delivery.show_url}
                                >#{run.webhook_delivery.id}</a
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Event/status</dt>
                        <dd class="font-medium">
                            {run.webhook_delivery.event_type} · {run
                                .webhook_delivery.status}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Request</dt>
                        <dd class="break-all font-medium">
                            {run.webhook_delivery.request_id ?? '—'}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Received</dt>
                        <dd class="font-medium">
                            {formatDateTime(run.webhook_delivery.received_at)}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Error</dt>
                        <dd class="font-medium">
                            {run.webhook_delivery.error ?? '—'}
                        </dd>
                    </div>
                </dl>
            {:else}
                <div class="text-sm text-muted-foreground">
                    No webhook delivery is linked to this run.
                </div>
            {/if}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Pipeline events</h2>
        <div class="space-y-2 text-sm">
            {#each run.events as event (event.id)}
                <div class="rounded-md bg-muted/40 p-3">
                    <div class="flex flex-wrap gap-2 text-muted-foreground">
                        <span>{formatDateTime(event.created_at)}</span>
                        <span>[{event.level}]</span>
                        <span>{event.event_type}</span>
                        {#if event.command_id}<span
                                >command {event.command_id}</span
                            >{/if}
                        {#if event.webhook_delivery_id}<span
                                >webhook {event.webhook_delivery_id}</span
                            >{/if}
                        {#if event.paperless_document_id}<span
                                >document {event.paperless_document_id}</span
                            >{/if}
                    </div>
                    {#if event.message}<div class="mt-1 break-words">
                            {event.message}
                        </div>{/if}
                    <pre class="mt-2 overflow-x-auto text-xs">{pretty(
                            event.payload,
                        )}</pre>
                </div>
            {:else}
                <div class="text-muted-foreground">
                    No events are linked to this run.
                </div>
            {/each}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Pipeline items</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-muted-foreground"
                    ><tr
                        ><th class="py-2">Item</th><th class="py-2">Status</th
                        ><th class="py-2">Attempt</th><th class="py-2"
                            >Document</th
                        ><th class="py-2">Error</th></tr
                    ></thead
                >
                <tbody>
                    {#each run.items as item (item.id)}
                        <tr class="border-t"
                            ><td class="py-2">{item.item_type}</td><td
                                class="py-2">{item.status}</td
                            ><td class="py-2"
                                >{item.attempt}/{item.max_attempts}</td
                            ><td class="py-2"
                                >{item.paperless_document_id ?? '—'}</td
                            ><td class="py-2">{item.error ?? '—'}</td></tr
                        >
                    {:else}
                        <tr
                            ><td class="py-4 text-muted-foreground" colspan="5"
                                >No pipeline items are linked to this run.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Linked worker jobs</h2>
            <div class="space-y-2 text-sm">
                {#each run.linked_worker_jobs as job (job.id)}
                    <div class="rounded-md bg-muted/40 p-3">
                        <a
                            class="font-medium underline-offset-4 hover:underline"
                            href={job.show_url}>Worker job {job.id}</a
                        >
                        <div class="text-muted-foreground">
                            {job.type} · {job.status} · document {job.paperless_document_id ??
                                '—'}
                        </div>
                        <div class="break-all text-xs text-muted-foreground">
                            {job.dispatch_key ?? '—'}
                        </div>
                    </div>
                {:else}
                    <div class="text-muted-foreground">
                        No related temporary worker jobs were found.
                    </div>
                {/each}
            </div>
        </div>

        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Linked audit log entries</h2>
            <div class="space-y-2 text-sm">
                {#each run.audit_logs as log (log.id)}
                    <div class="rounded-md bg-muted/40 p-3">
                        <div class="font-medium">{log.event}</div>
                        <div class="text-muted-foreground">
                            {formatDateTime(log.created_at)} · {log.target_type}
                            {log.target_id}
                        </div>
                        <pre class="mt-2 overflow-x-auto text-xs">{pretty(
                                log.metadata,
                            )}</pre>
                    </div>
                {:else}
                    <div class="text-muted-foreground">
                        No audit entries are linked to this run.
                    </div>
                {/each}
            </div>
        </div>
    </section>
</div>
