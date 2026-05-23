<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'OCR reviews',
                href: '/ocr-reviews',
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { formatDateTime } from '@/lib/datetime';

    type OcrReview = {
        id: number;
        paperless_document_id: number;
        status: string;
        write_back_error: string | null;
        created_at: string | null;
    };

    type Paginator<T> = {
        data: T[];
        total: number;
    };

    let {
        reviews,
        autoWriteBackEnabled,
    }: { reviews: Paginator<OcrReview>; autoWriteBackEnabled: boolean } =
        $props();
</script>

<AppHead title="OCR reviews" />

<div class="space-y-6">
    <Heading
        title="OCR reviews"
        description="Review OCR text before replacing Paperless document content. Paperless reprocessing can also regenerate OCR content if needed."
    />

    {#if autoWriteBackEnabled}
        <div
            class="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
        >
            Automatic OCR write-back is enabled globally. Newly created OCR
            reviews will immediately replace Paperless document content after
            preserving the original for restore. This should be scoped per
            Paperless account before multi-user use.
        </div>
    {/if}

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {reviews.total} OCR review{reviews.total === 1 ? '' : 's'}
        </div>

        {#each reviews.data as review (review.id)}
            <div
                class="grid gap-3 border-b p-4 last:border-b-0 md:grid-cols-[1fr_auto]"
            >
                <div class="space-y-1">
                    <div class="text-sm text-muted-foreground">
                        Paperless document reference {review.paperless_document_id}
                    </div>
                    <h2 class="font-medium">OCR review {review.id}</h2>
                    <p class="text-sm text-muted-foreground">
                        Status: {review.status} · Created {formatDateTime(
                            review.created_at,
                        )}
                        {#if review.write_back_error}
                            · Last write-back failed
                        {/if}
                    </p>
                </div>

                <Button variant="outline" asChild>
                    {#snippet children(props)}
                        <Link
                            href={`/ocr-reviews/${review.id}`}
                            class={props.class}
                        >
                            Review
                        </Link>
                    {/snippet}
                </Button>
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No OCR reviews have been created yet.
            </div>
        {/each}
    </div>
</div>
