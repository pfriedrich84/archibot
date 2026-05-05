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
        correspondent: number | null;
        document_type: number | null;
        tags: number[];
        review: {
            id: number;
            status: string;
            proposed_title: string | null;
        } | null;
    };

    let {
        documents,
        inboxTagId,
        error,
    }: {
        documents: InboxDocument[];
        inboxTagId: number;
        error: string | null;
    } = $props();
</script>

<AppHead title="Inbox" />

<div class="space-y-6">
    <Heading
        title="Inbox"
        description="Paperless inbox documents loaded with the current user's Paperless token."
    />

    <div
        class="rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground"
    >
        Inbox tag ID: {inboxTagId || 'not configured'}
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
                    <span class="font-medium"
                        >#{document.id} {document.title || 'Untitled'}</span
                    >
                    {#if document.created_date}
                        <span class="text-muted-foreground"
                            >{document.created_date}</span
                        >
                    {/if}
                </div>
                <div class="text-xs text-muted-foreground">
                    Correspondent: {document.correspondent ?? '—'} · Type: {document.document_type ??
                        '—'} · Tags: {document.tags.join(', ') || '—'}
                </div>
                {#if document.review}
                    <div class="text-xs">
                        Review:
                        <a
                            class="underline"
                            href={reviewShow(document.review.id).url}
                        >
                            #{document.review.id} · {document.review.status}
                            {#if document.review.proposed_title}
                                · {document.review.proposed_title}
                            {/if}
                        </a>
                    </div>
                {:else}
                    <div class="text-xs text-muted-foreground">
                        No Laravel review suggestion yet.
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
