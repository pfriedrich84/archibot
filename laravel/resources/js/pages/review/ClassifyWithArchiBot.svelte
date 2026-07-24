<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Classify with ArchiBot',
                href: '/review/classify-with-archibot',
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

    let { actions }: { actions: { store: string } } = $props();
</script>

<AppHead title="Classify with ArchiBot" />

<div class="space-y-6">
    <Heading
        title="Classify with ArchiBot"
        description="Create a document-bound ArchiBot review for a specific Paperless document ID. Authorization and Paperless version safety are enforced before the request is queued."
    />

    <Form action={actions.store} method="post" class="grid max-w-xl gap-5 rounded-xl border p-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="paperless_document_id">Paperless document ID</Label>
                <Input id="paperless_document_id" name="paperless_document_id" type="number" min="1" required />
                <p class="text-sm text-muted-foreground">
                    Use the Paperless document ID from the document URL. ArchiBot will re-read the document and its current version before queuing classification.
                </p>
                <InputError message={errors.paperless_document_id} />
            </div>

            <div>
                <Button type="submit" disabled={processing}>
                    {#if processing}<Spinner />{/if}
                    Queue classification
                </Button>
            </div>
        {/snippet}
    </Form>
</div>
