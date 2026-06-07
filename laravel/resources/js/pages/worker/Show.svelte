<script module lang="ts">
    import { index as workerJobsIndex } from '@/routes/worker-jobs';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Control Center',
                href: workerJobsIndex(),
            },
            {
                title: 'Worker row detail',
                href: '#',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { formatDateTime } from '@/lib/datetime';
    import { displayEntries, formatDisplayValue } from '@/lib/display';
    import { show as reviewShow } from '@/routes/review';
    import { show as workerJobShow } from '@/routes/worker-jobs';

    type JsonObject = Record<string, unknown>;

    type WorkerJobLink = {
        id: number;
        type: string;
        status: string;
        created_at: string | null;
        finished_at: string | null;
    };

    type WorkerJobDetail = WorkerJobLink & {
        payload: JsonObject;
        result: JsonObject;
        progress: JsonObject & {
            phase?: string;
            done?: number;
            total?: number;
            failed?: number;
            message?: string;
            cancellation?: JsonObject;
            force_killed_by_admin?: boolean;
        };
        ingest: JsonObject;
        dispatch_key: string | null;
        dispatch_attempts: number;
        dispatched_at: string | null;
        worker_id: string | null;
        lease_expires_at: string | null;
        heartbeat_at: string | null;
        retry_of_worker_job_id: number | null;
        cancellation_requested_at: string | null;
        exit_code: number | null;
        error: string | null;
        input_path: string | null;
        output_path: string | null;
        input_exists: boolean;
        output_exists: boolean;
        created_by: { id: number; name: string; email: string } | null;
        started_at: string | null;
    };

    type WorkerLog = {
        id: number;
        stream: string;
        level: string;
        event: string | null;
        paperless_document_id: number | null;
        phase: string | null;
        message: string;
        context: JsonObject;
        created_at: string | null;
    };

    type Paginator<T> = {
        data: T[];
        total: number;
    };

    type ReviewSuggestion = {
        id: number;
        paperless_document_id: number;
        source_suggestion_id: string | null;
        dedupe_key: string | null;
        proposed_title: string | null;
        status: string;
        confidence: number | null;
        created_at: string | null;
    };

    type AuditLog = {
        id: number;
        event: string;
        actor_user_id: number | null;
        metadata: JsonObject;
        created_at: string | null;
    };

    let {
        job,
        logs,
        reviewSuggestions,
        retryParent,
        retryChildren,
        auditLogs,
        isAdmin,
        actions,
    }: {
        job: WorkerJobDetail;
        logs: Paginator<WorkerLog>;
        reviewSuggestions: ReviewSuggestion[];
        retryParent: WorkerJobLink | null;
        retryChildren: WorkerJobLink[];
        auditLogs: AuditLog[];
        isAdmin: boolean;
        actions: {
            can_stop: boolean;
            can_retry: boolean;
            can_retry_failed_only: boolean;
            can_force_kill: boolean;
            stop_url: string;
            retry_url: string;
            force_kill_url: string;
        } | null;
    } = $props();

    const prettyJson = (value: unknown) => JSON.stringify(value ?? {}, null, 2);

    const reliabilityItems = $derived([
        { label: 'Dispatch key', value: job.dispatch_key ?? '—' },
        {
            label: 'Dispatch attempts',
            value: String(job.dispatch_attempts ?? 0),
        },
        { label: 'Dispatched at', value: formatDateTime(job.dispatched_at) },
        { label: 'Worker ID', value: job.worker_id ?? '—' },
        {
            label: 'Lease expires at',
            value: formatDateTime(job.lease_expires_at),
        },
        { label: 'Heartbeat at', value: formatDateTime(job.heartbeat_at) },
        {
            label: 'Retry parent',
            value: job.retry_of_worker_job_id
                ? `Worker job ${job.retry_of_worker_job_id}`
                : '—',
        },
        {
            label: 'Cancellation requested at',
            value: job.cancellation_requested_at ?? '—',
        },
        { label: 'Exit code', value: job.exit_code ?? '—' },
        {
            label: 'Input JSON',
            value: job.input_path
                ? `${job.input_path} (${job.input_exists ? 'exists' : 'missing'})`
                : '—',
        },
        {
            label: 'Output JSON',
            value: job.output_path
                ? `${job.output_path} (${job.output_exists ? 'exists' : 'missing'})`
                : '—',
        },
    ]);

    const progressTotal = $derived(Number(job.progress.total ?? 0));
    const progressDone = $derived(Number(job.progress.done ?? 0));
    const progressPercent = $derived(
        progressTotal > 0
            ? Math.min(100, Math.round((progressDone / progressTotal) * 100))
            : 0,
    );

    onMount(() => {
        const interval = window.setInterval(() => {
            if (['queued', 'running', 'cancelling'].includes(job.status)) {
                router.reload({ only: ['job', 'logs'] });
            }
        }, 3000);

        return () => window.clearInterval(interval);
    });
</script>

<AppHead title={`Worker job ${job.id}`} />

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <Heading
            title={`Worker job ${job.id}`}
            description={`${job.type} · ${job.status}`}
        />

        {#if isAdmin && actions}
            <div class="flex flex-wrap gap-2">
                {#if actions.can_stop}
                    <Form method="post" action={actions.stop_url}>
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}>Stop</Button
                            >
                        {/snippet}
                    </Form>
                {/if}
                {#if actions.can_force_kill}
                    <Form method="post" action={actions.force_kill_url}>
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}>Force kill</Button
                            >
                        {/snippet}
                    </Form>
                {/if}
                {#if actions.can_retry}
                    <Form method="post" action={actions.retry_url}>
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}>Retry whole job</Button
                            >
                        {/snippet}
                    </Form>
                    {#if actions.can_retry_failed_only}
                        <Form method="post" action={actions.retry_url}>
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="failed_only"
                                    value="1"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    >Retry failed documents only</Button
                                >
                            {/snippet}
                        </Form>
                    {/if}
                {/if}
            </div>
        {/if}
    </div>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Metadata</h2>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-sm text-muted-foreground">Created</dt>
                <dd>{formatDateTime(job.created_at)}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Started</dt>
                <dd>{formatDateTime(job.started_at)}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Finished</dt>
                <dd>{formatDateTime(job.finished_at)}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Created by</dt>
                <dd>{job.created_by?.email ?? '—'}</dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Reliability</h2>
        <dl class="grid gap-3 lg:grid-cols-2">
            {#each reliabilityItems as item (item.label)}
                <div class="grid gap-1 sm:grid-cols-[12rem_1fr]">
                    <dt class="text-sm text-muted-foreground">{item.label}</dt>
                    <dd class="break-words text-sm">{item.value}</dd>
                </div>
            {/each}
        </dl>
        {#if job.progress.cancellation}
            <div class="mt-4">
                <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                    Cancellation metadata
                </h3>
                <pre
                    class="overflow-auto rounded-md bg-muted p-3 text-xs">{prettyJson(
                        job.progress.cancellation,
                    )}</pre>
            </div>
        {/if}
        {#if job.progress.force_killed_by_admin}
            <div
                class="mt-3 rounded-md border border-destructive/40 p-3 text-sm text-destructive"
            >
                Force-kill metadata is present in progress.
            </div>
        {/if}
        {#if job.error}
            <div
                class="mt-3 whitespace-pre-wrap rounded-md border border-destructive/40 p-3 text-sm text-destructive"
            >
                {job.error}
            </div>
        {/if}
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Progress</h2>
        <div class="space-y-3">
            <div class="flex flex-wrap gap-3 text-sm">
                <span>Phase: {job.progress.phase ?? '—'}</span>
                <span>{progressDone}/{progressTotal}</span>
                <span>Failed: {job.progress.failed ?? 0}</span>
            </div>
            {#if progressTotal > 0}
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-primary"
                        style={`width: ${progressPercent}%`}
                    ></div>
                </div>
            {/if}
            {#if job.progress.message}
                <p class="text-sm text-muted-foreground">
                    {job.progress.message}
                </p>
            {/if}
            <pre
                class="overflow-auto rounded-md bg-muted p-3 text-xs">{prettyJson(
                    job.progress,
                )}</pre>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Payload</h2>
            <pre
                class="overflow-auto rounded-md bg-muted p-3 text-xs">{prettyJson(
                    job.payload,
                )}</pre>
        </div>
        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Result</h2>
            {#if displayEntries(job.ingest).length > 0}
                <dl class="mb-3 grid gap-1 rounded-md bg-muted/50 p-3 text-xs">
                    {#each displayEntries(job.ingest) as entry (entry.key)}
                        <div class="grid gap-1 sm:grid-cols-[10rem_1fr]">
                            <dt class="text-muted-foreground">{entry.label}</dt>
                            <dd class="break-words">{entry.value}</dd>
                        </div>
                    {/each}
                </dl>
            {/if}
            <pre
                class="overflow-auto rounded-md bg-muted p-3 text-xs">{prettyJson(
                    job.result,
                )}</pre>
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Logs ({logs.total})</h2>
        <div class="space-y-2 text-xs">
            {#each logs.data as log (log.id)}
                <div class="rounded-md bg-muted/40 p-3">
                    <div class="flex flex-wrap gap-2 text-muted-foreground">
                        <span>{formatDateTime(log.created_at)}</span>
                        <span>[{log.level}]</span>
                        <span>{log.stream}</span>
                        {#if log.event}<span>{log.event}</span>{/if}
                        {#if log.phase}<span>phase {log.phase}</span>{/if}
                        {#if log.paperless_document_id}<span
                                >document {log.paperless_document_id}</span
                            >{/if}
                    </div>
                    <div class="mt-1 break-words">{log.message}</div>
                    {#if displayEntries(log.context).length > 0}
                        <div class="mt-1 break-words text-muted-foreground">
                            {formatDisplayValue(log.context)}
                        </div>
                    {/if}
                </div>
            {:else}
                <div class="text-muted-foreground">
                    No logs recorded for this worker job.
                </div>
            {/each}
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Review suggestions</h2>
            <div class="space-y-2 text-sm">
                {#each reviewSuggestions as suggestion (suggestion.id)}
                    <a
                        class="block rounded-md border p-3 hover:underline"
                        href={reviewShow(suggestion.id).url}
                    >
                        {suggestion.proposed_title ??
                            `Review suggestion ${suggestion.id}`}
                        <span class="text-muted-foreground">
                            · document {suggestion.paperless_document_id} · {suggestion.status}</span
                        >
                        {#if suggestion.dedupe_key}
                            <span class="block text-xs text-muted-foreground"
                                >dedupe {suggestion.dedupe_key}</span
                            >
                        {/if}
                    </a>
                {:else}
                    <div class="text-muted-foreground">
                        No review suggestions linked to this job.
                    </div>
                {/each}
            </div>
        </div>

        <div class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Retry history</h2>
            <div class="space-y-2 text-sm">
                {#if retryParent}
                    <a
                        class="block rounded-md border p-3 hover:underline"
                        href={workerJobShow(retryParent.id).url}
                    >
                        Parent: worker job {retryParent.id} · {retryParent.type} ·
                        {retryParent.status}
                    </a>
                {/if}
                {#each retryChildren as retryChild (retryChild.id)}
                    <a
                        class="block rounded-md border p-3 hover:underline"
                        href={workerJobShow(retryChild.id).url}
                    >
                        Retry child: worker job {retryChild.id} · {retryChild.type}
                        · {retryChild.status}
                    </a>
                {/each}
                {#if !retryParent && retryChildren.length === 0}
                    <div class="text-muted-foreground">
                        No retry parent or children.
                    </div>
                {/if}
            </div>
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Audit logs</h2>
        <div class="space-y-2 text-xs">
            {#each auditLogs as auditLog (auditLog.id)}
                <div class="rounded-md bg-muted/40 p-3">
                    <div class="text-muted-foreground">
                        {formatDateTime(auditLog.created_at)} · {auditLog.event} ·
                        actor {auditLog.actor_user_id ?? 'system'}
                    </div>
                    {#if displayEntries(auditLog.metadata).length > 0}
                        <div class="mt-1 break-words">
                            {formatDisplayValue(auditLog.metadata)}
                        </div>
                    {/if}
                </div>
            {:else}
                <div class="text-muted-foreground">
                    No audit logs recorded for this worker job.
                </div>
            {/each}
        </div>
    </section>
</div>
