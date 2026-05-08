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
        write_back_error: string | null;
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
        description={`Paperless document reference ${review.paperless_document_id}. Review and edit OCR text before writing it back.`}
    />

    <div
        class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
    >
        ArchiBot stores the original Paperless content before write-back. You
        can restore that stored content here, and Paperless “Reprocessing” can
        also regenerate OCR content from the source document.
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full bg-muted px-3 py-1 text-sm">
            Status: {review.status}
        </span>
        {#if review.write_back_error}
            <span
                class="rounded-full bg-destructive/10 px-3 py-1 text-sm text-destructive"
            >
                Write-back failed: {review.write_back_error}
            </span>
        {/if}
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Current Paperless content</h2>
            <pre
                class="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-md bg-muted p-3 text-sm">{review.original_content}</pre>
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Editable OCR content</h2>
            <textarea
                bind:value={approvedContent}
                class="min-h-[60vh] w-full rounded-md border bg-background p-3 font-mono text-sm"
                aria-label="Approved OCR content"
            ></textarea>
        </section>
    </div>

    {#if review.status === 'pending' || review.status === 'write_back_failed'}
        <div class="flex flex-wrap gap-3">
            <Form method="post" action={`/ocr-reviews/${review.id}/approve`}>
                {#snippet children({ processing })}
                    <input
                        type="hidden"
                        name="approved_content"
                        value={approvedContent}
                    />
                    <Button type="submit" disabled={processing}>
                        {review.status === 'write_back_failed'
                            ? 'Retry write-back'
                            : 'Approve and write back'}
                    </Button>
                {/snippet}
            </Form>

            {#if review.status === 'pending'}
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
            {/if}
        </div>
    {/if}

    {#if review.status === 'written_back' || review.status === 'write_back_failed'}
        <Form method="post" action={`/ocr-reviews/${review.id}/restore`}>
            {#snippet children({ processing })}
                <Button type="submit" variant="outline" disabled={processing}>
                    Restore original Paperless content
                </Button>
            {/snippet}
        </Form>
    {/if}
</div>
