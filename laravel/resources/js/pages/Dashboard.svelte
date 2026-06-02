<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';
    import { index as reviewIndex } from '@/routes/review';
    import { index as workerJobsIndex } from '@/routes/worker-jobs';

    type DashboardStatus = {
        setup_complete: boolean;
        paperless_url_configured: boolean;
        user_paperless_token_present: boolean;
        paperless_available: boolean | null;
        paperless_error: string | null;
        inbox_tag_id: number;
        inbox_tag_label: string | null;
        llm_provider: string | null;
        ollama_or_provider_configured: boolean;
        ocr_mode: string | null;
    };

    type Counts = {
        pending_reviews: number;
        queued_or_running_workers: number;
        queued_worker_jobs: number;
        running_worker_jobs: number;
        cancelling_worker_jobs: number;
        failed_workers: number;
        failed_worker_jobs: number;
        stale_queued_worker_jobs: number;
        stale_running_worker_jobs: number;
        queued_webhook_deliveries: number;
        active_pipeline_runs: number;
        blocked_pipeline_runs: number;
        failed_pipeline_runs: number;
        running_actor_executions: number;
        failed_actor_executions: number;
    };

    type EmbeddingIndex = {
        id: number | null;
        status: string;
        embedding_model: string | null;
        document_count: number;
        embedded_count: number;
        failed_count: number;
        started_at: string | null;
        completed_at: string | null;
        error: string | null;
        ready: boolean;
        pending_build_commands: number;
        build_url: string;
        mark_stale_url: string;
    };

    type MaintenanceState = {
        poll_url: string;
        reindex_url: string;
        pending_poll_commands: number;
        pending_reindex_commands: number;
        poll_interval_seconds: number;
        last_worker_recovery_successful_at: string | null;
        last_worker_recovery_error: string | null;
        last_worker_recovery_error_at: string | null;
        worker_queue_warning: string | null;
        document_processing_active: boolean;
        reindex_active: boolean;
    };

    type RecentWebhookDelivery = {
        id: number;
        event_type: string;
        status: string;
        paperless_document_id: number | null;
        error: string | null;
        received_at: string | null;
        processed_at: string | null;
        show_url: string;
        retry_url: string;
        dismiss_url: string;
        can_retry: boolean;
        can_dismiss: boolean;
    };

    type RecentActorExecution = {
        id: number;
        pipeline_run_id: number | null;
        actor_name: string;
        queue_name: string | null;
        status: string;
        attempt: number;
        worker_id: string | null;
        progress_total: number;
        progress_done: number;
        progress_failed: number;
        progress_current_item: string | null;
        progress_message: string | null;
        duration_ms: number | null;
        error_type: string | null;
        started_at: string | null;
        finished_at: string | null;
    };

    type RecentPipelineRun = {
        id: number;
        type: string;
        status: string;
        trigger_source: string;
        paperless_document_id: number | null;
        progress_total: number;
        progress_done: number;
        progress_failed: number;
        progress_skipped: number;
        progress_current_phase: string | null;
        progress_message: string | null;
        reprocess_requested: boolean;
        created_at: string | null;
        updated_at: string | null;
        retry_url: string;
        retry_failed_items_url: string;
        cancel_url: string;
        failed_items_count: number;
        can_retry: boolean;
        can_retry_failed_items: boolean;
        can_cancel: boolean;
    };

    type RecentWorkerJob = {
        id: number;
        type: string;
        status: string;
        created_at: string | null;
        started_at: string | null;
        finished_at: string | null;
        error: string | null;
    };

    type RecentError = {
        source: string;
        id: number;
        status: string;
        message: string | null;
        occurred_at: string | null;
    };

    let {
        status,
        counts,
        embeddingIndex,
        maintenance,
        recentWebhookDeliveries,
        recentActorExecutions,
        recentPipelineRuns,
        lastSuccessfulWorkerJob,
        lastFailedWorkerJob,
        recentErrors,
        recentWorkerJobs,
    }: {
        status: DashboardStatus;
        counts: Counts;
        embeddingIndex: EmbeddingIndex;
        maintenance: MaintenanceState;
        recentWebhookDeliveries: RecentWebhookDelivery[];
        recentActorExecutions: RecentActorExecution[];
        recentPipelineRuns: RecentPipelineRun[];
        lastSuccessfulWorkerJob: RecentWorkerJob | null;
        lastFailedWorkerJob: RecentWorkerJob | null;
        recentErrors: RecentError[];
        recentWorkerJobs: RecentWorkerJob[];
    } = $props();

    const isAdmin = $derived(Boolean(page.props.auth.user?.is_admin));

    type SystemCheck = {
        label: string;
        ok: boolean | null;
        detail: string;
        problem?: string;
    };

    const paperlessLabel = $derived(
        status.paperless_available === null
            ? 'Not checked'
            : status.paperless_available
              ? 'Available'
              : 'Unavailable',
    );

    const systemChecks: SystemCheck[] = $derived([
        {
            label: 'Setup',
            ok: status.setup_complete,
            detail: status.setup_complete
                ? 'Onboarding completed'
                : 'Onboarding flag is incomplete',
            problem: status.setup_complete
                ? undefined
                : 'Setup is not marked complete. If processing works, this is likely stale setup state rather than a runtime blocker.',
        },
        {
            label: 'Paperless URL',
            ok: status.paperless_url_configured,
            detail: status.paperless_url_configured ? 'Configured' : 'Missing',
            problem: status.paperless_url_configured
                ? undefined
                : 'Configure the Paperless URL before polling documents.',
        },
        {
            label: 'Paperless token',
            ok: status.user_paperless_token_present,
            detail: status.user_paperless_token_present
                ? 'Configured'
                : 'Missing',
            problem: status.user_paperless_token_present
                ? undefined
                : 'Add a Paperless API token for this user.',
        },
        {
            label: 'Paperless API',
            ok: status.paperless_available,
            detail: paperlessLabel,
            problem:
                status.paperless_available === false
                    ? (status.paperless_error ??
                      'Paperless is currently unreachable.')
                    : undefined,
        },
        {
            label: 'Inbox tag',
            ok: status.inbox_tag_id > 0,
            detail: status.inbox_tag_id
                ? (status.inbox_tag_label ??
                  `Unknown Paperless tag (#${status.inbox_tag_id})`)
                : 'Missing',
            problem: status.inbox_tag_id
                ? undefined
                : 'Configure the inbox tag used to find incoming documents.',
        },
        {
            label: 'AI provider',
            ok: status.ollama_or_provider_configured,
            detail: status.llm_provider ?? 'Default provider configured',
            problem: status.ollama_or_provider_configured
                ? undefined
                : 'Configure Ollama or another LLM provider.',
        },
        {
            label: 'OCR mode',
            ok: status.ocr_mode ? true : null,
            detail: status.ocr_mode ?? 'Not configured',
        },
    ]);

    const systemProblems = $derived(
        systemChecks.filter((check) => check.ok === false && check.problem),
    );

    function indicatorClass(ok: boolean | null): string {
        if (ok === true) {
            return 'bg-emerald-500';
        }

        if (ok === false) {
            return 'bg-destructive';
        }

        return 'bg-amber-500';
    }

    function indicatorLabel(ok: boolean | null): string {
        if (ok === true) {
            return 'OK';
        }

        if (ok === false) {
            return 'Problem';
        }

        return 'Check';
    }
</script>

<AppHead title="Dashboard" />

<div class="space-y-6">
    <Heading title="Dashboard" description="ArchiBot application status." />

    <div class="grid gap-4 md:grid-cols-3">
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={reviewIndex().url}
        >
            <div class="text-sm text-muted-foreground">Pending reviews</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.pending_reviews}
            </div>
        </a>
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={workerJobsIndex().url}
        >
            <div class="text-sm text-muted-foreground">
                Queued/running workers
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.queued_or_running_workers}
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
                {counts.queued_worker_jobs} queued · {counts.running_worker_jobs}
                running · {counts.cancelling_worker_jobs} cancelling
            </div>
        </a>
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={workerJobsIndex().url}
        >
            <div class="text-sm text-muted-foreground">Failed workers</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.failed_workers}
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
                {counts.stale_queued_worker_jobs} stale queued · {counts.stale_running_worker_jobs}
                stale running
            </div>
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Running actors</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.running_actor_executions}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Failed actors</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.failed_actor_executions}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Queued webhooks</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.queued_webhook_deliveries}
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">
                Active pipeline runs
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.active_pipeline_runs}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">
                Blocked pipeline runs
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.blocked_pipeline_runs}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">
                Failed pipeline runs
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.failed_pipeline_runs}
            </div>
        </div>
    </div>

    <section class="rounded-xl border p-4">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">System status</h2>
                <p class="text-sm text-muted-foreground">
                    Runtime checks and actionable configuration problems.
                </p>
            </div>
            <div
                class="rounded-full px-3 py-1 text-xs font-medium {systemProblems.length
                    ? 'bg-destructive/10 text-destructive'
                    : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'}"
            >
                {systemProblems.length
                    ? `${systemProblems.length} problem${systemProblems.length === 1 ? '' : 's'}`
                    : 'All clear'}
            </div>
        </div>

        <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-3">
            {#each systemChecks as check (check.label)}
                <div class="rounded-lg border p-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="font-medium">{check.label}</div>
                        <div
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                class="h-2.5 w-2.5 rounded-full {indicatorClass(
                                    check.ok,
                                )}"
                                aria-hidden="true"
                            ></span>
                            {indicatorLabel(check.ok)}
                        </div>
                    </div>
                    <div class="mt-1 text-muted-foreground">{check.detail}</div>
                </div>
            {/each}
        </div>

        {#if systemProblems.length}
            <div
                class="mt-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3"
            >
                <h3 class="text-sm font-medium text-destructive">
                    Problems to fix
                </h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    {#each systemProblems as problem (problem.label)}
                        <li>
                            <span class="font-medium">{problem.label}:</span>
                            {problem.problem}
                        </li>
                    {/each}
                </ul>
            </div>
        {/if}
    </section>

    <section class="rounded-xl border p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">Embedding index</h2>
                <p class="text-sm text-muted-foreground">
                    Document processing is blocked until this index is complete.
                </p>
            </div>
            {#if isAdmin}
                <div class="flex flex-wrap gap-2">
                    <Form method="post" action={embeddingIndex.build_url}>
                        {#snippet children({ processing })}
                            <button
                                type="submit"
                                class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                disabled={processing}
                            >
                                {embeddingIndex.status === 'failed' ||
                                embeddingIndex.status === 'stale'
                                    ? 'Resume embedding build'
                                    : 'Start embedding build'}
                            </button>
                        {/snippet}
                    </Form>
                    <Form method="post" action={embeddingIndex.mark_stale_url}>
                        {#snippet children({ processing })}
                            <button
                                type="submit"
                                class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                disabled={processing}
                            >
                                Mark embedding index stale
                            </button>
                        {/snippet}
                    </Form>
                </div>
            {/if}
        </div>
        <dl class="grid gap-3 text-sm md:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Status</dt>
                <dd>{embeddingIndex.status}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Readiness</dt>
                <dd>{embeddingIndex.ready ? 'Ready' : 'Not ready'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Progress</dt>
                <dd>
                    {embeddingIndex.embedded_count} / {embeddingIndex.document_count}
                    embedded
                    {#if embeddingIndex.failed_count}
                        · {embeddingIndex.failed_count} failed{/if}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Pending build commands</dt>
                <dd>{embeddingIndex.pending_build_commands}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Model</dt>
                <dd>{embeddingIndex.embedding_model ?? 'Not recorded'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Started</dt>
                <dd>
                    {formatDateTime(embeddingIndex.started_at, 'Not started')}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Completed</dt>
                <dd>
                    {formatDateTime(
                        embeddingIndex.completed_at,
                        'Not complete',
                    )}
                </dd>
            </div>
        </dl>
        {#if embeddingIndex.error}
            <p class="mt-3 text-sm text-destructive">{embeddingIndex.error}</p>
        {/if}
    </section>

    <section class="rounded-xl border p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">Maintenance</h2>
                <p class="text-sm text-muted-foreground">
                    Automatic polling reconciliation runs every {maintenance.poll_interval_seconds}
                    seconds.
                </p>
            </div>
            {#if isAdmin}
                <div class="flex flex-wrap gap-2">
                    <Form method="post" action={maintenance.poll_url}>
                        {#snippet children({ processing })}
                            <button
                                type="submit"
                                class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                disabled={processing}
                            >
                                Run poll now
                            </button>
                        {/snippet}
                    </Form>
                    <Form method="post" action={maintenance.reindex_url}>
                        {#snippet children({ processing })}
                            <button
                                type="submit"
                                class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                disabled={processing}
                            >
                                Start reindex
                            </button>
                        {/snippet}
                    </Form>
                </div>
            {/if}
        </div>
        {#if maintenance.worker_queue_warning}
            <div
                class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200"
            >
                {maintenance.worker_queue_warning}
            </div>
        {/if}
        <dl class="grid gap-3 text-sm md:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Pending poll commands</dt>
                <dd>{maintenance.pending_poll_commands}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Pending reindex commands</dt>
                <dd>{maintenance.pending_reindex_commands}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Poll interval</dt>
                <dd>{maintenance.poll_interval_seconds} seconds</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    Document processing active
                </dt>
                <dd>{maintenance.document_processing_active ? 'Yes' : 'No'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Reindex active</dt>
                <dd>{maintenance.reindex_active ? 'Yes' : 'No'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    Last worker recovery success
                </dt>
                <dd>
                    {formatDateTime(
                        maintenance.last_worker_recovery_successful_at,
                        'Unknown',
                    )}
                </dd>
            </div>
            <div class="md:col-span-3">
                <dt class="text-muted-foreground">
                    Last worker recovery error
                </dt>
                <dd>
                    {#if maintenance.last_worker_recovery_error}
                        {maintenance.last_worker_recovery_error}
                        {#if maintenance.last_worker_recovery_error_at}
                            · {formatDateTime(
                                maintenance.last_worker_recovery_error_at,
                            )}
                        {/if}
                    {:else}
                        None recorded
                    {/if}
                </dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Worker readiness</h2>
        <dl class="grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">
                    Last successful worker job
                </dt>
                <dd>
                    {#if lastSuccessfulWorkerJob}
                        #{lastSuccessfulWorkerJob.id} · {lastSuccessfulWorkerJob.type}
                        · {formatDateTime(
                            lastSuccessfulWorkerJob.finished_at,
                            'not finished',
                        )}
                    {:else}
                        None recorded
                    {/if}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Last failed worker job</dt>
                <dd>
                    {#if lastFailedWorkerJob}
                        #{lastFailedWorkerJob.id} · {lastFailedWorkerJob.type} · {formatDateTime(
                            lastFailedWorkerJob.finished_at,
                            'not finished',
                        )}
                    {:else}
                        None recorded
                    {/if}
                </dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-semibold">Recent errors</div>
        {#each recentErrors as error (`${error.source}-${error.id}`)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium">{error.source} {error.id}</span>
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{error.status}</span
                >
                {#if error.occurred_at}
                    <span class="text-muted-foreground"
                        >{formatDateTime(error.occurred_at)}</span
                    >
                {/if}
                {#if error.message}
                    <span class="basis-full text-destructive"
                        >{error.message}</span
                    >
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No recent errors.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="flex items-center justify-between border-b px-4 py-3">
            <div class="font-semibold">Recent webhook deliveries</div>
            <a
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                href="/webhook-deliveries"
            >
                View all
            </a>
        </div>
        {#each recentWebhookDeliveries as delivery (delivery.id)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <a
                    class="font-medium underline-offset-4 hover:underline"
                    href={delivery.show_url}
                    >Webhook {delivery.id} · {delivery.event_type}</a
                >
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{delivery.status}</span
                >
                {#if delivery.paperless_document_id}
                    <span class="text-muted-foreground"
                        >document {delivery.paperless_document_id}</span
                    >
                {/if}
                {#if delivery.received_at}
                    <span class="text-muted-foreground"
                        >received {formatDateTime(delivery.received_at)}</span
                    >
                {/if}
                {#if delivery.processed_at}
                    <span class="text-muted-foreground"
                        >processed {formatDateTime(delivery.processed_at)}</span
                    >
                {/if}
                {#if delivery.error}
                    <span class="basis-full text-destructive"
                        >{delivery.error}</span
                    >
                {/if}
                {#if isAdmin && (delivery.can_retry || delivery.can_dismiss)}
                    <div class="basis-full pt-2">
                        <div class="flex gap-2">
                            {#if delivery.can_retry}
                                <Form method="post" action={delivery.retry_url}>
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                            disabled={processing}
                                        >
                                            Retry webhook delivery
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                            {#if delivery.can_dismiss}
                                <Form
                                    method="post"
                                    action={delivery.dismiss_url}
                                >
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                            disabled={processing}
                                        >
                                            Dismiss webhook failure
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                        </div>
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No webhook deliveries yet.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-semibold">
            Recent actor executions
        </div>
        {#each recentActorExecutions as execution (execution.id)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium"
                    >Actor {execution.id} · {execution.actor_name}</span
                >
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{execution.status}</span
                >
                {#if execution.queue_name}
                    <span class="text-muted-foreground"
                        >queue {execution.queue_name}</span
                    >
                {/if}
                {#if execution.pipeline_run_id}
                    <span class="text-muted-foreground"
                        >run {execution.pipeline_run_id}</span
                    >
                {/if}
                <span class="text-muted-foreground">
                    progress {execution.progress_done}/{execution.progress_total}
                    {#if execution.progress_failed}
                        · failed {execution.progress_failed}{/if}
                </span>
                {#if execution.progress_current_item}
                    <span class="text-muted-foreground"
                        >item {execution.progress_current_item}</span
                    >
                {/if}
                {#if execution.error_type}
                    <span class="text-destructive">{execution.error_type}</span>
                {/if}
                {#if execution.progress_message}
                    <span class="basis-full text-muted-foreground"
                        >{execution.progress_message}</span
                    >
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No actor executions yet.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-semibold">Recent pipeline runs</div>
        {#each recentPipelineRuns as run (run.id)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium"
                    >Run {run.id} · {run.type} · {run.trigger_source}</span
                >
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{run.status}</span
                >
                {#if run.paperless_document_id}
                    <span class="text-muted-foreground"
                        >document {run.paperless_document_id}</span
                    >
                {/if}
                {#if run.reprocess_requested}
                    <span class="rounded-full bg-muted px-2 py-0.5"
                        >reprocess</span
                    >
                {/if}
                <span class="text-muted-foreground">
                    progress {run.progress_done}/{run.progress_total}
                    {#if run.progress_failed}
                        · failed {run.progress_failed}{/if}
                    {#if run.progress_skipped}
                        · skipped {run.progress_skipped}{/if}
                </span>
                {#if run.progress_current_phase}
                    <span class="text-muted-foreground"
                        >phase {run.progress_current_phase}</span
                    >
                {/if}
                {#if run.failed_items_count}
                    <span class="text-destructive"
                        >failed items {run.failed_items_count}</span
                    >
                {/if}
                {#if run.progress_message}
                    <span class="basis-full text-muted-foreground"
                        >{run.progress_message}</span
                    >
                {/if}
                {#if isAdmin && (run.can_retry || run.can_retry_failed_items || run.can_cancel)}
                    <div class="basis-full pt-2">
                        <div class="flex gap-2">
                            {#if run.can_retry}
                                <Form method="post" action={run.retry_url}>
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                            disabled={processing}
                                        >
                                            Retry
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                            {#if run.can_retry_failed_items}
                                <Form
                                    method="post"
                                    action={run.retry_failed_items_url}
                                >
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                            disabled={processing}
                                        >
                                            Retry failed items
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                            {#if run.can_cancel}
                                <Form method="post" action={run.cancel_url}>
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                            disabled={processing}
                                        >
                                            Cancel
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                        </div>
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No pipeline runs yet.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-semibold">Recent worker jobs</div>
        {#each recentWorkerJobs as job (job.id)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium">Worker job {job.id} · {job.type}</span
                >
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{job.status}</span
                >
                {#if job.finished_at}
                    <span class="text-muted-foreground"
                        >finished {formatDateTime(job.finished_at)}</span
                    >
                {:else if job.created_at}
                    <span class="text-muted-foreground"
                        >created {formatDateTime(job.created_at)}</span
                    >
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No worker jobs yet.
            </div>
        {/each}
    </section>
</div>
