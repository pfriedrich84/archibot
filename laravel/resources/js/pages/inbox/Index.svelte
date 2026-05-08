<script module lang="ts">
    import { index as inboxIndex } from '@/routes/inbox';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Inbox',
                href: inboxIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { show as reviewShow } from '@/routes/review';

    type InboxDocument = {
        id: number;
        title: string;
        created_date: string | null;
        correspondent_id: number | null;
        correspondent_name: string | null;
        document_type_id: number | null;
        document_type_name: string | null;
        tags: { id: number; name: string | null }[];
        review: {
            id: number;
            status: string;
            proposed_title: string | null;
        } | null;
    };

    let {
        documents,
        inboxTagId,
        inboxTagName,
        kpis,
        error,
    }: {
        documents: InboxDocument[];
        inboxTagId: number;
        inboxTagName: string | null;
        kpis: {
            total: number;
            with_review: number;
            without_review: number;
            pending_review: number;
        };
        error: string | null;
    } = $props();
</script>

<AppHead title="Inbox" />

<div class="space-y-6">
    <Heading
        title="Inbox"
        description="Paperless inbox documents loaded with the current user's Paperless token."
    />

    <div class="grid gap-4 md:grid-cols-4">
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Inbox documents</div>
            <div class="mt-2 text-3xl font-semibold">{kpis.total}</div>
        </section>
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">With review</div>
            <div class="mt-2 text-3xl font-semibold">{kpis.with_review}</div>
        </section>
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Without review</div>
            <div class="mt-2 text-3xl font-semibold">
                {kpis.without_review}
            </div>
        </section>
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Pending review</div>
            <div class="mt-2 text-3xl font-semibold">
                {kpis.pending_review}
            </div>
        </section>
    </div>

    <div
        class="rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground"
    >
        Inbox tag: {inboxTagName ??
            `reference ${inboxTagId || 'not configured'}`}
    </div>

    {#if error}
        <div
            class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive"
        >
            {error}
        </div>
    {/if}

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {documents.length} inbox document{documents.length === 1 ? '' : 's'}
        </div>

        {#each documents as document (document.id)}
            <div class="grid gap-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">
                        {document.title || `Document reference ${document.id}`}
                    </span>
                    {#if document.created_date}
                        <span class="text-muted-foreground">
                            {document.created_date}
                        </span>
                    {/if}
                </div>
                <div class="text-xs text-muted-foreground">
                    Document reference {document.id} · Correspondent: {document.correspondent_name ??
                        (document.correspondent_id
                            ? `reference ${document.correspondent_id}`
                            : '—')} · Type: {document.document_type_name ??
                        (document.document_type_id
                            ? `reference ${document.document_type_id}`
                            : '—')} · Tags: {document.tags
                        .map((tag) => tag.name ?? `reference ${tag.id}`)
                        .join(', ') || '—'}
                </div>
                {#if document.review}
                    <div class="text-xs">
                        Review:
                        <a
                            class="underline"
                            href={reviewShow(document.review.id).url}
                        >
                            Review suggestion {document.review.id} · {document
                                .review.status}
                            {#if document.review.proposed_title}
                                · {document.review.proposed_title}
                            {/if}
                        </a>
                    </div>
                {:else}
                    <div class="text-xs text-muted-foreground">
                        No ArchiBot review suggestion yet.
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No inbox documents loaded.
            </div>
        {/each}
    </div>
</div>
