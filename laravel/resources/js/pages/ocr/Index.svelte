<script module lang="ts">
    import { index as ocrReviewsIndex } from '@/routes/ocr-reviews';

    export const layout = {
        breadcrumbs: [
            {
                title: 'OCR reviews',
                href: ocrReviewsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import Pagination from '@/components/Pagination.svelte';
    import { Button } from '@/components/ui/button';
    import { formatDateTime } from '@/lib/datetime';
    import { show } from '@/routes/ocr-reviews';
    import type { Paginator } from '@/types';

    type OcrReview = {
        id: number;
        paperless_document_id: number;
        status: string;
        created_at: string | null;
    };

    let { reviews }: { reviews: Paginator<OcrReview> } = $props();
</script>

<AppHead title="OCR reviews" />

<div class="space-y-6">
    <Heading
        title="OCR reviews"
        description="Review OCR correction snapshots stored locally in ArchiBot. These corrections never replace Paperless document content."
    />

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {reviews.total} accessible OCR review{reviews.total === 1
                ? ''
                : 's'}
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
                    </p>
                </div>

                <Button variant="outline" asChild>
                    {#snippet children(props)}
                        <Link href={show(review.id)} class={props.class}>
                            Review
                        </Link>
                    {/snippet}
                </Button>
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No accessible OCR reviews are available.
            </div>
        {/each}
        <Pagination
            links={reviews.links}
            from={reviews.from}
            to={reviews.to}
            total={reviews.total}
            perPage={reviews.per_page}
            label="OCR review pages"
        />
    </div>
</div>
