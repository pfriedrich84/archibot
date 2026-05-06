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
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { show as reviewShow } from '@/routes/review';
    import { store } from '@/routes/worker-jobs';

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
        ingest: Record<string, unknown>;
        review_suggestions_count: number;
        review_suggestions: ReviewSuggestionLink[];
        exit_code: number | null;
        error: string | null;
        created_at: string | null;
        started_at: string | null;
        finished_at: string | null;
    };

    type Paginator<T> = {
        data: T[];
        total: number;
    };

    let {
        jobs,
        allowedTypes,
    }: {
        jobs: Paginator<WorkerJob>;
        allowedTypes: string[];
    } = $props();
</script>

<AppHead title="Worker jobs" />

<div class="space-y-6">
    <Heading
        title="Worker jobs"
        description="Queue ArchiBot worker commands and inspect their results."
    />

    <Form {...store.form()} class="grid max-w-2xl gap-4 rounded-xl border p-4">
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
                <Label for="paperless_document_id">Paperless document ID</Label>
                <Input
                    id="paperless_document_id"
                    name="paperless_document_id"
                    type="number"
                    min="1"
                    placeholder="Required only for process_document"
                />
                <InputError message={errors.paperless_document_id} />
            </div>

            <Button type="submit" disabled={processing} class="w-fit">
                {#if processing}<Spinner />{/if}
                Queue worker job
            </Button>
        {/snippet}
    </Form>

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {jobs.total} worker job{jobs.total === 1 ? '' : 's'}
        </div>

        {#each jobs.data as job (job.id)}
            <div class="grid gap-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">#{job.id} {job.type}</span>
                    <span class="rounded-full bg-muted px-2 py-0.5"
                        >{job.status}</span
                    >
                    {#if job.exit_code !== null}
                        <span class="text-muted-foreground"
                            >exit {job.exit_code}</span
                        >
                    {/if}
                </div>
                <code class="break-all text-xs text-muted-foreground">
                    {JSON.stringify(job.payload)}
                </code>
                {#if Object.keys(job.ingest).length > 0}
                    <div class="text-xs text-muted-foreground">
                        Ingest: {JSON.stringify(job.ingest)}
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
                                    Suggestion #{suggestion.id} · Document #{suggestion.paperless_document_id}
                                    {#if suggestion.proposed_title}
                                        · {suggestion.proposed_title}
                                    {/if}
                                    · {suggestion.status}
                                </a>
                            {/each}
                        </div>
                    </div>
                {/if}
                {#if job.error}
                    <div class="text-destructive">{job.error}</div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No worker jobs yet.
            </div>
        {/each}
    </div>
</div>
