<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { displayEntries } from '@/lib/display';
    import { accept, reject } from '@/routes/review';

    type EntityOption = { id: number; name: string };

    type Suggestion = {
        id: number;
        paperless_document_id: number;
        status: string;
        confidence: number | null;
        reasoning: string | null;
        commit_status: string | null;
        commit_worker_job_id: number | null;
        preview_url: string;
        judge_verdict: string | null;
        judge_reasoning: string | null;
        original: Record<string, unknown>;
        proposed: Record<string, unknown>;
        context_documents: Record<string, unknown>[];
        save_url: string;
    };

    let {
        suggestion,
        entityOptions,
    }: {
        suggestion: Suggestion;
        entityOptions: {
            correspondents: EntityOption[];
            documentTypes: EntityOption[];
            storagePaths: EntityOption[];
        };
    } = $props();
</script>

<AppHead title={`Review document ${suggestion.paperless_document_id}`} />

<div class="space-y-6">
    <Heading
        title={`Review document ${suggestion.paperless_document_id}`}
        description="ArchiBot owns this review state. Accepting suggestions queues a worker commit back to Paperless."
    />

    <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full bg-muted px-3 py-1 text-sm">
            Status: {suggestion.status}
        </span>
        {#if suggestion.commit_status}
            <span class="rounded-full bg-muted px-3 py-1 text-sm">
                Commit: {suggestion.commit_status}
                {#if suggestion.commit_worker_job_id}
                    via worker reference {suggestion.commit_worker_job_id}
                {/if}
            </span>
        {/if}
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

    <section class="rounded-xl border p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="font-semibold">Document preview</h2>
            <a
                class="text-sm text-muted-foreground underline"
                href={suggestion.preview_url}
                target="_blank"
                rel="noreferrer"
            >
                Open preview
            </a>
        </div>
        <iframe
            title={`Preview document ${suggestion.paperless_document_id}`}
            src={suggestion.preview_url}
            class="h-[70vh] w-full rounded-md border bg-white"
        ></iframe>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Original</h2>
            {#each displayEntries(suggestion.original) as entry (entry.key)}
                <div
                    class="grid grid-cols-[10rem_1fr] gap-3 border-t py-2 text-sm first:border-t-0"
                >
                    <div class="text-muted-foreground">{entry.label}</div>
                    <div class="break-words">{entry.value}</div>
                </div>
            {/each}
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Proposed</h2>
            {#each displayEntries(suggestion.proposed) as entry (entry.key)}
                <div
                    class="grid grid-cols-[10rem_1fr] gap-3 border-t py-2 text-sm first:border-t-0"
                >
                    <div class="text-muted-foreground">{entry.label}</div>
                    <div class="break-words">{entry.value}</div>
                </div>
            {/each}
        </section>
    </div>

    {#if suggestion.status === 'pending'}
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Edit proposed values</h2>
            <Form
                method="post"
                action={suggestion.save_url}
                class="grid gap-3 md:grid-cols-2"
            >
                {#snippet children({ processing })}
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">Title</span>
                        <input
                            name="proposed_title"
                            value={String(suggestion.proposed.title ?? '')}
                            class="h-9 rounded-md border bg-background px-3"
                        />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">Date</span>
                        <input
                            name="proposed_date"
                            type="date"
                            value={String(suggestion.proposed.date ?? '')}
                            class="h-9 rounded-md border bg-background px-3"
                        />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">Correspondent</span>
                        <select
                            name="proposed_correspondent_id"
                            class="h-9 rounded-md border bg-background px-3"
                        >
                            <option value="">No selected correspondent</option>
                            {#each entityOptions.correspondents as option (option.id)}
                                <option
                                    value={option.id}
                                    selected={option.id ===
                                        suggestion.proposed.correspondent_id}
                                >
                                    {option.name}
                                </option>
                            {/each}
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground"
                            >Correspondent name</span
                        >
                        <input
                            name="proposed_correspondent_name"
                            value={String(
                                suggestion.proposed.correspondent_name ?? '',
                            )}
                            class="h-9 rounded-md border bg-background px-3"
                        />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">Document type</span>
                        <select
                            name="proposed_document_type_id"
                            class="h-9 rounded-md border bg-background px-3"
                        >
                            <option value="">No selected document type</option>
                            {#each entityOptions.documentTypes as option (option.id)}
                                <option
                                    value={option.id}
                                    selected={option.id ===
                                        suggestion.proposed.document_type_id}
                                >
                                    {option.name}
                                </option>
                            {/each}
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground"
                            >Document type name</span
                        >
                        <input
                            name="proposed_document_type_name"
                            value={String(
                                suggestion.proposed.document_type_name ?? '',
                            )}
                            class="h-9 rounded-md border bg-background px-3"
                        />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">Storage path</span>
                        <select
                            name="proposed_storage_path_id"
                            class="h-9 rounded-md border bg-background px-3"
                        >
                            <option value="">No selected storage path</option>
                            {#each entityOptions.storagePaths as option (option.id)}
                                <option
                                    value={option.id}
                                    selected={option.id ===
                                        suggestion.proposed.storage_path_id}
                                >
                                    {option.name}
                                </option>
                            {/each}
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground"
                            >Storage path name</span
                        >
                        <input
                            name="proposed_storage_path_name"
                            value={String(
                                suggestion.proposed.storage_path_name ?? '',
                            )}
                            class="h-9 rounded-md border bg-background px-3"
                        />
                    </label>
                    <div class="md:col-span-2">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}>Save proposed values</Button
                        >
                    </div>
                {/snippet}
            </Form>
        </section>
    {/if}

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

    {#if suggestion.status === 'pending'}
        <div class="flex gap-3">
            <Form {...accept.form(suggestion.id)}>
                {#snippet children({ processing })}
                    <Button type="submit" disabled={processing}>Accept</Button>
                {/snippet}
            </Form>
            <Form {...reject.form(suggestion.id)}>
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        Reject
                    </Button>
                {/snippet}
            </Form>
        </div>
    {/if}
</div>
