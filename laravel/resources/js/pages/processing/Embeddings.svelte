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
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { Spinner } from '@/components/ui/spinner';
    import { store } from '@/routes/worker-jobs';

    type EmbeddingItem = {
        document_id: number;
        title: string | null;
        correspondent: number | null;
        doctype: number | null;
        storage_path: number | null;
        tags: Array<number | string>;
        created_date: string | null;
        indexed_at: string | null;
    };

    type WorkerJob = {
        id: number;
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

    let {
        snapshot,
        latestReindexJob,
    }: {
        snapshot: {
            db_path: string;
            error: string | null;
            total_embedded: number;
            items: EmbeddingItem[];
        };
        latestReindexJob: WorkerJob | null;
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
</script>

<AppHead title="Embeddings" />

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <Heading
            title="Embeddings"
            description="Vector index status and recently indexed Paperless documents. This replaces the old /app/embeddings status page."
        />

        <Form {...store.form()}>
            {#snippet children({ processing })}
                <input type="hidden" name="type" value="reindex" />
                <Button type="submit" disabled={processing}>
                    {#if processing}<Spinner />{/if}
                    Rebuild embeddings
                </Button>
            {/snippet}
        </Form>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Embedded documents</div>
            <div class="mt-2 text-3xl font-semibold">
                {snapshot.total_embedded}
            </div>
        </div>
        <div class="rounded-xl border p-4 md:col-span-2">
            <div class="text-sm text-muted-foreground">Python database</div>
            <code class="mt-2 block break-all text-sm">{snapshot.db_path}</code>
            {#if snapshot.error}
                <div
                    class="mt-3 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {snapshot.error}
                </div>
            {/if}
        </div>
    </div>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3">
            <div class="font-medium">Processing status</div>
            <div class="text-sm text-muted-foreground">
                Latest reindex / embedding worker job.
            </div>
        </div>

        {#if latestReindexJob}
            <div class="space-y-3 p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">Job #{latestReindexJob.id}</span>
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
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full bg-primary"
                            style={`width: ${progressPercent}%`}
                        ></div>
                    </div>
                {/if}

                {#if latestReindexJob.progress.message || latestReindexJob.progress.document_id}
                    <div class="text-muted-foreground">
                        {latestReindexJob.progress.message ?? 'Last update'}
                        {#if latestReindexJob.progress.document_id}
                            · Document #{latestReindexJob.progress.document_id}
                        {/if}
                        {#if latestReindexJob.progress.document_title}
                            · {latestReindexJob.progress.document_title}
                        {/if}
                    </div>
                {/if}

                {#if latestReindexJob.error}
                    <div class="text-destructive">{latestReindexJob.error}</div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No reindex job has been queued yet.
            </div>
        {/if}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            Recent embeddings ({snapshot.items.length} shown)
        </div>

        {#each snapshot.items as item (item.document_id)}
            <div class="grid gap-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">Document #{item.document_id}</span
                    >
                    {#if item.created_date}
                        <span class="text-muted-foreground"
                            >{item.created_date}</span
                        >
                    {/if}
                </div>
                <div>{item.title || 'Untitled'}</div>
                <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                    {#if item.correspondent}<span
                            >Correspondent #{item.correspondent}</span
                        >{/if}
                    {#if item.doctype}<span>Type #{item.doctype}</span>{/if}
                    {#if item.storage_path}<span
                            >Storage #{item.storage_path}</span
                        >{/if}
                    {#if item.tags.length > 0}<span
                            >Tags: {item.tags.join(', ')}</span
                        >{/if}
                    {#if item.indexed_at}<span>Indexed {item.indexed_at}</span
                        >{/if}
                </div>
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No embeddings indexed yet.
            </div>
        {/each}
    </section>
</div>
