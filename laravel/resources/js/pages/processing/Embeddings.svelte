<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Embeddings',
                href: '/embeddings',
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
    import { Spinner } from '@/components/ui/spinner';

    type Snapshot = {
        status: string;
        embedding_model: string | null;
        dimensions: number | null;
        document_count: number;
        document_count_known: boolean;
        embedded_count: number;
        stored_embedding_rows: number;
        missing_count: number | null;
        failed_count: number;
        started_at: string | null;
        completed_at: string | null;
        error: string | null;
        document_count_error: string | null;
        ready: boolean;
    };

    type WorkerJob = {
        id: number;
        type: string;
        status: string;
        progress: {
            phase?: string;
            done?: number;
            total?: number;
            failed?: number;
            message?: string;
            document_id?: number;
            document_title?: string | null;
        };
        result: Record<string, unknown>;
        error: string | null;
        created_at: string | null;
        started_at: string | null;
        finished_at: string | null;
    };

    type EmbeddingBuildCommand = {
        id: number;
        status: string;
        error: string | null;
        created_at: string | null;
        updated_at: string | null;
    };

    let {
        snapshot,
        latestReindexJob,
        latestEmbeddingBuildCommand,
        buildUrl,
        markStaleUrl,
    }: {
        snapshot: Snapshot;
        latestReindexJob: WorkerJob | null;
        latestEmbeddingBuildCommand: EmbeddingBuildCommand | null;
        buildUrl: string;
        markStaleUrl: string;
    } = $props();

    const progressPercent = $derived(
        latestReindexJob && (latestReindexJob.progress.total ?? 0) > 0
            ? Math.min(
                  100,
                  Math.round(
                      ((latestReindexJob.progress.done ?? 0) /
                          (latestReindexJob.progress.total ?? 1)) *
                          100,
                  ),
              )
            : 0,
    );

    const knownDocumentCount = $derived(
        snapshot.document_count_known
            ? String(snapshot.document_count)
            : 'Unknown',
    );
    const missingCount = $derived(
        snapshot.missing_count === null
            ? 'Unknown'
            : String(snapshot.missing_count),
    );

    onMount(() => {
        const interval = window.setInterval(() => {
            if (
                latestReindexJob &&
                ['queued', 'running', 'cancelling'].includes(
                    latestReindexJob.status,
                )
            ) {
                router.reload({
                    only: [
                        'snapshot',
                        'latestReindexJob',
                        'latestEmbeddingBuildCommand',
                    ],
                });
            }
        }, 3000);

        return () => window.clearInterval(interval);
    });
</script>

<AppHead title="Embeddings" />

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <Heading
            title="Embeddings"
            description="PostgreSQL/pgvector embedding index status for Paperless documents. Legacy Python SQLite metadata is no longer used here."
        />

        <div class="flex flex-wrap gap-2">
            <Form method="post" action={buildUrl}>
                {#snippet children({ processing })}
                    <Button type="submit" disabled={processing}>
                        {#if processing}<Spinner />{/if}
                        Start / resume embedding build
                    </Button>
                {/snippet}
            </Form>
            <Form method="post" action={markStaleUrl}>
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        Mark stale
                    </Button>
                {/snippet}
            </Form>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Index status</div>
            <div class="mt-2 text-2xl font-semibold">
                {snapshot.ready ? 'Ready' : snapshot.status}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Documents found</div>
            <div class="mt-2 text-3xl font-semibold">
                {knownDocumentCount}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Embedded documents</div>
            <div class="mt-2 text-3xl font-semibold">
                {snapshot.embedded_count}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Not yet embedded</div>
            <div class="mt-2 text-3xl font-semibold">{missingCount}</div>
        </div>
    </div>

    <section class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Failed embeddings</div>
            <div class="mt-2 text-3xl font-semibold">
                {snapshot.failed_count}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">
                Stored pgvector rows
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {snapshot.stored_embedding_rows}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Model / dimensions</div>
            <div class="mt-2 text-sm font-medium">
                {snapshot.embedding_model ?? 'Not recorded'}
                {#if snapshot.dimensions}
                    · {snapshot.dimensions} dimensions
                {/if}
            </div>
        </div>
    </section>

    {#if snapshot.error || snapshot.document_count_error}
        <section
            class="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
        >
            {snapshot.error ?? snapshot.document_count_error}
        </section>
    {/if}

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3">
            <div class="font-medium">Embedding build status</div>
            <div class="text-sm text-muted-foreground">
                Durable pgvector build command and latest legacy reindex worker,
                if any.
            </div>
        </div>

        <div class="space-y-4 p-4 text-sm">
            {#if latestEmbeddingBuildCommand}
                <div class="rounded-md border p-3">
                    <div class="font-medium">
                        Embedding build command {latestEmbeddingBuildCommand.id}
                    </div>
                    <div class="text-muted-foreground">
                        Status: {latestEmbeddingBuildCommand.status}
                        {#if latestEmbeddingBuildCommand.updated_at}
                            · Updated {latestEmbeddingBuildCommand.updated_at}
                        {/if}
                    </div>
                    {#if latestEmbeddingBuildCommand.error}
                        <div class="mt-2 text-destructive">
                            {latestEmbeddingBuildCommand.error}
                        </div>
                    {/if}
                </div>
            {:else}
                <div class="text-muted-foreground">
                    No pgvector embedding build command has been queued yet.
                </div>
            {/if}

            {#if latestReindexJob}
                <div class="rounded-md border p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium"
                            >Legacy worker job {latestReindexJob.id} · {latestReindexJob.type}</span
                        >
                        <span class="rounded-full bg-muted px-2 py-0.5"
                            >{latestReindexJob.status}</span
                        >
                        <span class="text-muted-foreground">
                            Phase: {latestReindexJob.progress.phase ?? '—'} · {latestReindexJob
                                .progress.done ?? 0}/{latestReindexJob.progress
                                .total ?? 0}
                        </span>
                        {#if latestReindexJob.progress.failed}
                            <span class="text-destructive"
                                >{latestReindexJob.progress.failed} failed</span
                            >
                        {/if}
                    </div>

                    {#if (latestReindexJob.progress.total ?? 0) > 0}
                        <div
                            class="mt-3 h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full bg-primary"
                                style={`width: ${progressPercent}%`}
                            ></div>
                        </div>
                    {/if}

                    {#if latestReindexJob.progress.message || latestReindexJob.progress.document_id}
                        <div class="mt-2 text-muted-foreground">
                            {latestReindexJob.progress.message ?? 'Last update'}
                            {#if latestReindexJob.progress.document_title}
                                · {latestReindexJob.progress.document_title}
                            {:else if latestReindexJob.progress.document_id}
                                · Document reference {latestReindexJob.progress
                                    .document_id}
                            {/if}
                        </div>
                    {/if}

                    {#if latestReindexJob.error}
                        <div class="mt-2 text-destructive">
                            {latestReindexJob.error}
                        </div>
                    {/if}
                </div>
            {/if}
        </div>
    </section>
</div>
