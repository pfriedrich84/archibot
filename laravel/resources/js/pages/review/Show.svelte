<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { accept, reject } from '@/routes/review';

    type Suggestion = {
        id: number;
        paperless_document_id: number;
        confidence: number | null;
        reasoning: string | null;
        judge_verdict: string | null;
        judge_reasoning: string | null;
        original: Record<string, unknown>;
        proposed: Record<string, unknown>;
        context_documents: Record<string, unknown>[];
    };

    let { suggestion }: { suggestion: Suggestion } = $props();

    function formatValue(value: unknown): string {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        if (Array.isArray(value) || typeof value === 'object') {
            return JSON.stringify(value);
        }

        return String(value);
    }
</script>

<AppHead title={`Review document #${suggestion.paperless_document_id}`} />

<div class="space-y-6">
    <Heading
        title={`Review document #${suggestion.paperless_document_id}`}
        description="Laravel owns this review state. Accept/reject currently records the review decision; worker commit wiring comes next."
    />

    <div class="flex flex-wrap items-center gap-3">
        {#if suggestion.confidence !== null}
            <span class="rounded-full bg-muted px-3 py-1 text-sm">
                {suggestion.confidence}% confidence
            </span>
        {/if}
        {#if suggestion.judge_verdict}
            <span class="rounded-full bg-muted px-3 py-1 text-sm">
                Judge: {suggestion.judge_verdict}
            </span>
        {/if}
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Original</h2>
            {#each Object.entries(suggestion.original) as [key, value] (key)}
                <div
                    class="grid grid-cols-[10rem_1fr] gap-3 border-t py-2 text-sm first:border-t-0"
                >
                    <div class="text-muted-foreground">{key}</div>
                    <div>{formatValue(value)}</div>
                </div>
            {/each}
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Proposed</h2>
            {#each Object.entries(suggestion.proposed) as [key, value] (key)}
                <div
                    class="grid grid-cols-[10rem_1fr] gap-3 border-t py-2 text-sm first:border-t-0"
                >
                    <div class="text-muted-foreground">{key}</div>
                    <div>{formatValue(value)}</div>
                </div>
            {/each}
        </section>
    </div>

    {#if suggestion.reasoning}
        <section class="rounded-xl border p-4">
            <h2 class="mb-2 font-semibold">Reasoning</h2>
            <p class="whitespace-pre-wrap text-sm">{suggestion.reasoning}</p>
        </section>
    {/if}

    {#if suggestion.judge_reasoning}
        <section class="rounded-xl border p-4">
            <h2 class="mb-2 font-semibold">Judge reasoning</h2>
            <p class="whitespace-pre-wrap text-sm">
                {suggestion.judge_reasoning}
            </p>
        </section>
    {/if}

    <div class="flex gap-3">
        <Form {...accept.form(suggestion.id)}>
            {#snippet children({ processing })}
                <Button type="submit" disabled={processing}>Accept</Button>
            {/snippet}
        </Form>
        <Form {...reject.form(suggestion.id)}>
            {#snippet children({ processing })}
                <Button type="submit" variant="outline" disabled={processing}>
                    Reject
                </Button>
            {/snippet}
        </Form>
    </div>
</div>
