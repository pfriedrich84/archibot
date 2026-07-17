<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';

    type OcrReview = {
        id: number;
        paperless_document_id: number;
        status: string;
        original_content: string;
        ocr_content: string;
        approved_content: string | null;
    };

    let { review }: { review: OcrReview } = $props();

    let approvedContent = $derived(
        review.approved_content ?? review.ocr_content,
    );
</script>

<AppHead title={`OCR review ${review.id}`} />

<div class="space-y-6">
    <Heading
        title={`OCR review ${review.id}`}
        description={`Paperless document reference ${review.paperless_document_id}. Compare and locally approve the OCR correction snapshot.`}
    />

    <div
        class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
    >
        Original, corrected and approved text are retained only as local
        ArchiBot snapshots. Approval does not change Paperless document content.
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full bg-muted px-3 py-1 text-sm">
            Status: {review.status}
        </span>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Original content snapshot</h2>
            <pre
                class="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-md bg-muted p-3 text-sm">{review.original_content}</pre>
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">OCR correction snapshot</h2>
            <textarea
                bind:value={approvedContent}
                class="min-h-[60vh] w-full rounded-md border bg-background p-3 font-mono text-sm"
                aria-label="Approved OCR content"
                readonly={review.status !== 'pending'}
            ></textarea>
        </section>
    </div>

    {#if review.status === 'pending'}
        <div class="flex flex-wrap gap-3">
            <Form method="post" action={`/ocr-reviews/${review.id}/approve`}>
                {#snippet children({ processing })}
                    <input
                        type="hidden"
                        name="approved_content"
                        value={approvedContent}
                    />
                    <Button type="submit" disabled={processing}>
                        Approve local snapshot
                    </Button>
                {/snippet}
            </Form>

            <Form method="post" action={`/ocr-reviews/${review.id}/reject`}>
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        Reject OCR result
                    </Button>
                {/snippet}
            </Form>
        </div>
    {/if}
</div>
