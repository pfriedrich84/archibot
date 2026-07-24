<script module lang="ts">
    import { index as embeddingsIndex } from '@/routes/embeddings';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Embeddings',
                href: embeddingsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';

    type Snapshot = {
        status: string;
        embedding_model: string | null;
        dimensions: number | null;
        document_count: number;
        document_count_known: boolean;
        embedded_count: number;
        stored_embedding_rows: number;
        pgvector_embedded_count: number;
        missing_count: number | null;
        failed_count: number;
        started_at: string | null;
        completed_at: string | null;
        error: string | null;
        document_count_error: string | null;
        ready: boolean;
        scope: string | null;
        release_threshold: number;
        release_target_population: number;
        release_status: string;
        released_at: string | null;
        released: boolean;
    };

    type EmbeddingBuildCommand = {
        id: number;
        type: string;
        status: string;
        queue: string | null;
        priority: number | null;
        error: string | null;
        created_at: string | null;
        updated_at: string | null;
    };

    let {
        snapshot,
        latestEmbeddingBuildCommand,
    }: {
        snapshot: Snapshot;
        latestEmbeddingBuildCommand: EmbeddingBuildCommand | null;
    } = $props();

    const activeStatuses = ['pending', 'queued', 'running', 'cancelling'];

    const snapshotProgressPercent = $derived(
        snapshot.document_count > 0
            ? Math.min(
                  100,
                  Math.round(
                      ((snapshot.embedded_count + snapshot.failed_count) /
                          snapshot.document_count) *
                          100,
                  ),
              )
            : 0,
    );

    const snapshotBuildActive = $derived(
        snapshot.status === 'building' ||
            (latestEmbeddingBuildCommand !== null &&
                activeStatuses.includes(latestEmbeddingBuildCommand.status)),
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
            if (snapshotBuildActive) {
                router.reload({
                    only: ['snapshot', 'latestEmbeddingBuildCommand'],
                });
            }
        }, 3000);

        return () => window.clearInterval(interval);
    });
</script>

<AppHead title="Embeddings" />

<div class="space-y-6">
    <Heading
        title="Embeddings"
        description="PostgreSQL/pgvector embedding index statistics for Paperless documents. Build and stale controls live in the Control Center."
    />

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

    <section class="grid gap-4 md:grid-cols-4">
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Release status</div>
            <div class="mt-2 text-2xl font-semibold">
                {snapshot.released ? 'Released' : snapshot.release_status}
            </div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Release threshold</div>
            <div class="mt-2 text-3xl font-semibold">{snapshot.release_threshold}</div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Target population</div>
            <div class="mt-2 text-3xl font-semibold">{snapshot.release_target_population}</div>
        </div>
        <div class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Index scope</div>
            <div class="mt-2 text-sm font-medium">{snapshot.scope ?? 'default'}</div>
        </div>
    </section>

    {#if snapshotBuildActive || snapshot.document_count > 0}
        <section class="rounded-xl border p-4">
            <div
                class="flex flex-wrap items-center justify-between gap-2 text-sm"
            >
                <div>
                    <div class="font-medium">
                        Current pgvector build progress
                    </div>
                    <div class="text-muted-foreground">
                        {snapshot.embedded_count + snapshot.failed_count} / {snapshot.document_count}
                        processed
                        {#if snapshot.failed_count}
                            · {snapshot.failed_count} failed
                        {/if}
                    </div>
                </div>
                <div class="rounded-full bg-muted px-2 py-0.5 text-xs">
                    {snapshot.status}
                </div>
            </div>
            {#if snapshot.document_count > 0}
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-primary transition-all"
                        style={`width: ${snapshotProgressPercent}%`}
                    ></div>
                </div>
            {/if}
        </section>
    {/if}

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
            {#if snapshot.pgvector_embedded_count !== snapshot.embedded_count}
                <div class="mt-1 text-xs text-muted-foreground">
                    Progress is reported by the completed embedding index state.
                </div>
            {/if}
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
                        Embedding command {latestEmbeddingBuildCommand.id} · {latestEmbeddingBuildCommand.type}
                    </div>
                    <div class="text-muted-foreground">
                        Status: {latestEmbeddingBuildCommand.status}
                        {#if latestEmbeddingBuildCommand.queue}
                            · Queue {latestEmbeddingBuildCommand.queue}
                        {/if}
                        {#if latestEmbeddingBuildCommand.priority !== null}
                            · Priority {latestEmbeddingBuildCommand.priority}
                        {/if}
                        {#if latestEmbeddingBuildCommand.updated_at}
                            · Updated {formatDateTime(
                                latestEmbeddingBuildCommand.updated_at,
                            )}
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
        </div>
    </section>
</div>
