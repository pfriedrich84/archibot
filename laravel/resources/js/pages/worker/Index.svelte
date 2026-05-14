<script module lang="ts">
    import { index as workerJobsIndex } from '@/routes/worker-jobs';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Worker jobs',
                href: workerJobsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { displayEntries, formatDisplayValue } from '@/lib/display';
    import { show as reviewShow } from '@/routes/review';
    import {
        retry,
        show as workerJobShow,
        stop,
        store,
    } from '@/routes/worker-jobs';

    type ReviewSuggestionLink = {
        id: number;
        paperless_document_id: number;
        proposed_title: string | null;
        status: string;
    };

    type WorkerJob = {
        id: number;
        type: string;
        status: string;
        payload: Record<string, unknown>;
        result: Record<string, unknown>;
        progress: {
            phase?: string;
            done?: number;
            total?: number;
            failed?: number;
            document_id?: number;
            document_title?: string | null;
            message?: string;
            event?: string;
        };
        ingest: Record<string, unknown>;
        review_suggestions_count: number;
        review_suggestions: ReviewSuggestionLink[];
        exit_code: number | null;
        error: string | null;
        created_at: string | null;
        started_at: string | null;
        finished_at: string | null;
        logs: {
            id: number;
            stream: string;
            level: string;
            event: string | null;
            paperless_document_id: number | null;
            phase: string | null;
            message: string;
            context: Record<string, unknown>;
            created_at: string | null;
        }[];
        failed_document_ids: number[];
    };

    type Paginator<T> = {
        data: T[];
        total: number;
    };

    let {
        jobs,
        allowedTypes,
        isAdmin,
        readiness,
    }: {
        jobs: Paginator<WorkerJob>;
        allowedTypes: string[];
        isAdmin: boolean;
        readiness: {
            queued: number;
            running: number;
            failed: number;
            last_finished_at: string | null;
            document_processing_active: boolean;
            reindex_active: boolean;
        };
    } = $props();

    const labelForStatus = (status: string) =>
        status === 'cancelling' ? 'wird abgebrochen' : status;

    const actionUrl = (job: WorkerJob, action: 'stop' | 'retry') =>
        action === 'stop' ? stop(job.id).url : retry(job.id).url;

    const readinessItems = $derived([
        {
            label: 'Document processing',
            value: readiness.document_processing_active ? 'Active' : 'Ready',
        },
        {
            label: 'Reindex/OCR',
            value: readiness.reindex_active ? 'Active' : 'Ready',
        },
        { label: 'Queued', value: String(readiness.queued) },
        { label: 'Running', value: String(readiness.running) },
        { label: 'Failed', value: String(readiness.failed) },
        {
            label: 'Last finished',
            value: readiness.last_finished_at ?? 'No finished job yet',
        },
    ]);

    onMount(() => {
        const interval = window.setInterval(() => {
            router.reload({
                only: ['jobs', 'readiness'],
            });
        }, 5000);

        return () => window.clearInterval(interval);
    });
</script>

<AppHead title="Worker jobs" />

<div class="space-y-6">
    <Heading
        title="Worker jobs"
        description="Queue ArchiBot worker commands and inspect their results."
    />

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Readiness</h2>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {#each readinessItems as item (item.label)}
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {item.label}
                    </dt>
                    <dd class="text-lg font-semibold">{item.value}</dd>
                </div>
            {/each}
        </dl>
    </section>

    {#if isAdmin}
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Quick controls</h2>
            <div class="flex flex-wrap gap-2">
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value="poll" />
                        <Button type="submit" disabled={processing}
                            >Start inbox poll</Button
                        >
                    {/snippet}
                </Form>
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value="poll" />
                        <input type="hidden" name="force" value="1" />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}>Start inbox poll force</Button
                        >
                    {/snippet}
                </Form>
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value="reindex" />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            >Start all-document reindex</Button
                        >
                    {/snippet}
                </Form>
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value="reindex_ocr" />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}>Start OCR reindex</Button
                        >
                    {/snippet}
                </Form>
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input type="hidden" name="type" value="reindex_ocr" />
                        <input type="hidden" name="force" value="1" />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            >Start OCR reindex force</Button
                        >
                    {/snippet}
                </Form>
                <Form method="post" action={store().url}>
                    {#snippet children({ processing })}
                        <input
                            type="hidden"
                            name="type"
                            value="reindex_embed"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            >Start embedding reindex</Button
                        >
                    {/snippet}
                </Form>
            </div>
        </section>

        <Form
            {...store.form()}
            class="grid max-w-2xl gap-4 rounded-xl border p-4"
        >
            {#snippet children({ errors, processing })}
                <input type="hidden" name="type" value="process_document" />
                <div class="grid gap-2">
                    <Label for="quick_paperless_document_id"
                        >Process document ID</Label
                    >
                    <Input
                        id="quick_paperless_document_id"
                        name="paperless_document_id"
                        type="number"
                        min="1"
                        required
                        placeholder="Paperless document reference"
                    />
                    <InputError message={errors.paperless_document_id} />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="force"
                        value="1"
                        class="h-4 w-4 rounded border-input"
                    />
                    Force process document
                </label>
                <InputError message={errors.force} />
                <Button type="submit" disabled={processing} class="w-fit">
                    {#if processing}<Spinner />{/if}
                    Process document
                </Button>
            {/snippet}
        </Form>

        <Form
            {...store.form()}
            class="grid max-w-2xl gap-4 rounded-xl border p-4"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="type">Job type</Label>
                    <select
                        id="type"
                        name="type"
                        required
                        class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs"
                    >
                        {#each allowedTypes as type (type)}
                            <option value={type}>{type}</option>
                        {/each}
                    </select>
                    <InputError message={errors.type} />
                </div>

                <div class="grid gap-2">
                    <Label for="paperless_document_id"
                        >Paperless document reference</Label
                    >
                    <Input
                        id="paperless_document_id"
                        name="paperless_document_id"
                        type="number"
                        min="1"
                        placeholder="Required only for process_document"
                    />
                    <InputError message={errors.paperless_document_id} />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="force"
                        value="1"
                        class="h-4 w-4 rounded border-input"
                    />
                    Force poll, process document, or OCR reindex
                </label>
                <InputError message={errors.force} />

                <Button type="submit" disabled={processing} class="w-fit">
                    {#if processing}<Spinner />{/if}
                    Queue worker job
                </Button>
            {/snippet}
        </Form>
    {:else}
        <div
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
        >
            Worker controls are available to administrators only.
        </div>
    {/if}

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {jobs.total} worker job{jobs.total === 1 ? '' : 's'}
        </div>

        {#each jobs.data as job (job.id)}
            <div class="grid gap-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        class="font-medium hover:underline"
                        href={workerJobShow(job.id).url}
                        >Worker job {job.id} · {job.type}</a
                    >
                    <span class="rounded-full bg-muted px-2 py-0.5"
                        >{labelForStatus(job.status)}</span
                    >
                    {#if job.exit_code !== null}
                        <span class="text-muted-foreground"
                            >exit {job.exit_code}</span
                        >
                    {/if}
                    {#if isAdmin && ['queued', 'running'].includes(job.status)}
                        <Form method="post" action={actionUrl(job, 'stop')}>
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}>Stop</Button
                                >
                            {/snippet}
                        </Form>
                    {/if}
                    {#if isAdmin && ['succeeded', 'failed', 'partially_failed', 'cancelled'].includes(job.status)}
                        <Form method="post" action={actionUrl(job, 'retry')}>
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                    >Retry whole job</Button
                                >
                            {/snippet}
                        </Form>
                        {#if job.failed_document_ids.length > 0}
                            <Form
                                method="post"
                                action={actionUrl(job, 'retry')}
                            >
                                {#snippet children({ processing })}
                                    <input
                                        type="hidden"
                                        name="failed_only"
                                        value="1"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        >Retry failed documents only</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                    {/if}
                </div>
                {#if displayEntries(job.payload).length > 0}
                    <dl class="grid gap-1 rounded-md bg-muted/40 p-3 text-xs">
                        {#each displayEntries(job.payload) as entry (entry.key)}
                            <div class="grid gap-1 sm:grid-cols-[10rem_1fr]">
                                <dt class="text-muted-foreground">
                                    {entry.label}
                                </dt>
                                <dd class="break-words">{entry.value}</dd>
                            </div>
                        {/each}
                    </dl>
                {/if}
                {#if Object.keys(job.progress).length > 0}
                    <div class="space-y-2 rounded-md bg-muted/50 p-3 text-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">Progress</span>
                            <span class="text-muted-foreground">
                                Phase: {job.progress.phase ?? '—'} · {job
                                    .progress.done ?? 0}/{job.progress.total ??
                                    0}
                                {#if job.progress.failed}
                                    · {job.progress.failed} failed
                                {/if}
                            </span>
                        </div>
                        {#if (job.progress.total ?? 0) > 0}
                            <div
                                class="h-2 overflow-hidden rounded-full bg-background"
                            >
                                <div
                                    class="h-full bg-primary"
                                    style={`width: ${Math.min(100, Math.round(((job.progress.done ?? 0) / (job.progress.total ?? 1)) * 100))}%`}
                                ></div>
                            </div>
                        {/if}
                        {#if job.progress.document_id || job.progress.message}
                            <div class="text-muted-foreground">
                                {job.progress.message ?? 'Last update'}
                                {#if job.progress.document_title}
                                    · {job.progress.document_title}
                                {:else if job.progress.document_id}
                                    · Document reference {job.progress
                                        .document_id}
                                {/if}
                            </div>
                        {/if}
                    </div>
                {/if}
                {#if displayEntries(job.ingest).length > 0}
                    <div class="space-y-1 rounded-md bg-muted/40 p-3 text-xs">
                        <div class="font-medium text-muted-foreground">
                            Ingest summary
                        </div>
                        <dl class="grid gap-1">
                            {#each displayEntries(job.ingest) as entry (entry.key)}
                                <div
                                    class="grid gap-1 sm:grid-cols-[10rem_1fr]"
                                >
                                    <dt class="text-muted-foreground">
                                        {entry.label}
                                    </dt>
                                    <dd class="break-words">{entry.value}</dd>
                                </div>
                            {/each}
                        </dl>
                    </div>
                {/if}
                {#if job.review_suggestions_count > 0}
                    <div class="space-y-1 rounded-md bg-muted/50 p-3">
                        <div class="text-xs font-medium text-muted-foreground">
                            {job.review_suggestions_count} review suggestion{job.review_suggestions_count ===
                            1
                                ? ''
                                : 's'} imported
                        </div>
                        <div class="flex flex-wrap gap-2">
                            {#each job.review_suggestions as suggestion (suggestion.id)}
                                <a
                                    class="rounded-md border bg-background px-2 py-1 text-xs hover:underline"
                                    href={reviewShow(suggestion.id).url}
                                >
                                    {suggestion.proposed_title ??
                                        `Review suggestion ${suggestion.id}`}
                                    · Document reference {suggestion.paperless_document_id}
                                    · {suggestion.status}
                                </a>
                            {/each}
                        </div>
                    </div>
                {/if}
                {#if job.error}
                    <div class="text-destructive">{job.error}</div>
                {/if}
                {#if job.logs.length > 0}
                    <details class="rounded-md bg-muted/40 p-3 text-xs">
                        <summary class="cursor-pointer font-medium"
                            >Logs ({job.logs.length})</summary
                        >
                        <div class="mt-2 max-h-72 space-y-1 overflow-auto">
                            {#each job.logs as log (log.id)}
                                <div class="break-words">
                                    <span class="text-muted-foreground"
                                        >{log.created_at ?? ''} [{log.level}] {log.phase ??
                                            log.stream}</span
                                    >
                                    {#if log.paperless_document_id}
                                        <span class="text-muted-foreground">
                                            document reference {log.paperless_document_id}</span
                                        >
                                    {/if}
                                    <span> · {log.message}</span>
                                    {#if displayEntries(log.context).length > 0}
                                        <span class="text-muted-foreground">
                                            · {formatDisplayValue(log.context)}
                                        </span>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    </details>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No worker jobs yet.
            </div>
        {/each}
    </div>
</div>
