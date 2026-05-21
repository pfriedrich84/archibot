<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { accept, reject } from '@/routes/review';

    type EntityOption = { id: number; name: string };

    type Suggestion = {
        id: number;
        paperless_document_id: number;
        status: string;
        confidence: number | null;
        reasoning: string | null;
        commit_status: string | null;
        worker_job_id: number | null;
        commit_worker_job_id: number | null;
        worker_job_url: string | null;
        commit_worker_job_url: string | null;
        preview_url: string;
        judge_verdict: string | null;
        judge_reasoning: string | null;
        original: Record<string, unknown>;
        proposed: Record<string, unknown>;
        context_documents: Record<string, unknown>[];
        save_url: string;
        reprocess_url: string;
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

    const numericId = (value: unknown): number | null => {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        const id = Number(value);

        return Number.isInteger(id) && id > 0 ? id : null;
    };

    let selectedStoragePathId = $derived<number | ''>(
        numericId(
            suggestion.proposed.storage_path_id ??
                suggestion.original.storage_path_id,
        ) ?? '',
    );

    const selectedStoragePathName = $derived(
        entityOptions.storagePaths.find(
            (option) => option.id === selectedStoragePathId,
        )?.name ??
            (selectedStoragePathId ===
            numericId(suggestion.proposed.storage_path_id)
                ? String(suggestion.proposed.storage_path_name ?? '')
                : ''),
    );

    const textValue = (value: unknown): string => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        return String(value);
    };

    const entityValue = (
        idValue: unknown,
        nameValue: unknown,
        options: EntityOption[],
    ): string => {
        const id = numericId(idValue);
        const explicitName =
            typeof nameValue === 'string' ? nameValue.trim() : '';
        const optionName = id
            ? options.find((option) => option.id === id)?.name
            : '';
        const label = explicitName || optionName || '';

        if (label && id) {
            return `${label} (#${id})`;
        }

        if (label) {
            return label;
        }

        if (id) {
            return `Unknown (#${id})`;
        }

        return '—';
    };

    const tagValues = (value: unknown): string => {
        if (!Array.isArray(value) || value.length === 0) {
            return '—';
        }

        return value
            .map((tag) => {
                if (typeof tag === 'string') {
                    return tag;
                }

                if (!tag || typeof tag !== 'object') {
                    return String(tag);
                }

                const record = tag as Record<string, unknown>;
                const id = numericId(record.id);
                const name =
                    typeof record.name === 'string' ? record.name.trim() : '';

                if (name && id) {
                    return `${name} (#${id})`;
                }

                if (name) {
                    return name;
                }

                if (id) {
                    return `Unknown (#${id})`;
                }

                return String(tag);
            })
            .join(', ');
    };

    const originalRows = $derived([
        { label: 'Title', value: textValue(suggestion.original.title) },
        { label: 'Date', value: textValue(suggestion.original.date) },
        {
            label: 'Correspondent',
            value: entityValue(
                suggestion.original.correspondent_id,
                suggestion.original.correspondent_name,
                entityOptions.correspondents,
            ),
        },
        {
            label: 'Document type',
            value: entityValue(
                suggestion.original.document_type_id,
                suggestion.original.document_type_name,
                entityOptions.documentTypes,
            ),
        },
        {
            label: 'Storage path',
            value: entityValue(
                suggestion.original.storage_path_id,
                suggestion.original.storage_path_name,
                entityOptions.storagePaths,
            ),
        },
        { label: 'Tags', value: tagValues(suggestion.original.tags) },
    ]);

    const proposedRows = $derived([
        { label: 'Title', value: textValue(suggestion.proposed.title) },
        { label: 'Date', value: textValue(suggestion.proposed.date) },
        {
            label: 'Correspondent',
            value: entityValue(
                suggestion.proposed.correspondent_id,
                suggestion.proposed.correspondent_name,
                entityOptions.correspondents,
            ),
        },
        {
            label: 'Document type',
            value: entityValue(
                suggestion.proposed.document_type_id,
                suggestion.proposed.document_type_name,
                entityOptions.documentTypes,
            ),
        },
        {
            label: 'Storage path',
            value: entityValue(
                suggestion.proposed.storage_path_id,
                suggestion.proposed.storage_path_name,
                entityOptions.storagePaths,
            ),
        },
        { label: 'Tags', value: tagValues(suggestion.proposed.tags) },
    ]);

    const isAdmin = $derived(Boolean(page.props.auth.user?.is_admin));
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
                    via
                    {#if suggestion.commit_worker_job_url}
                        <a
                            class="underline"
                            href={suggestion.commit_worker_job_url}
                        >
                            worker job {suggestion.commit_worker_job_id}
                        </a>
                    {:else}
                        worker reference {suggestion.commit_worker_job_id}
                    {/if}
                {/if}
            </span>
        {/if}
        {#if suggestion.worker_job_id}
            <span class="rounded-full bg-muted px-3 py-1 text-sm">
                Generated by
                {#if suggestion.worker_job_url}
                    <a class="underline" href={suggestion.worker_job_url}>
                        worker job {suggestion.worker_job_id}
                    </a>
                {:else}
                    worker reference {suggestion.worker_job_id}
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

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">Original</h2>
            {#each originalRows as entry (entry.label)}
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
            {#each proposedRows as entry (entry.label)}
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
                                    {option.name} (#{option.id})
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
                                    {option.name} (#{option.id})
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
                            bind:value={selectedStoragePathId}
                            class="h-9 rounded-md border bg-background px-3"
                        >
                            <option value="">No selected storage path</option>
                            {#each entityOptions.storagePaths as option (option.id)}
                                <option value={option.id}>{option.name}</option>
                            {/each}
                        </select>
                        <input
                            type="hidden"
                            name="proposed_storage_path_name"
                            value={selectedStoragePathName}
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

    <section class="rounded-xl border p-4">
        <h2 class="mb-2 font-semibold">Classification reasoning</h2>
        {#if suggestion.reasoning}
            <p class="whitespace-pre-wrap text-sm">{suggestion.reasoning}</p>
        {:else}
            <p class="text-sm text-muted-foreground">
                No classification reasoning was recorded for this suggestion.
            </p>
        {/if}
    </section>

    <section class="rounded-xl border p-4">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="font-semibold">Judge reasoning</h2>
            <span class="rounded-full bg-muted px-2 py-0.5 text-xs">
                Verdict: {suggestion.judge_verdict ?? 'not recorded'}
            </span>
        </div>
        {#if suggestion.judge_reasoning}
            <p class="whitespace-pre-wrap text-sm">
                {suggestion.judge_reasoning}
            </p>
        {:else}
            <p class="text-sm text-muted-foreground">
                No judge reasoning was recorded for this suggestion.
            </p>
        {/if}
    </section>

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

    {#if isAdmin}
        <section class="rounded-xl border p-4">
            <h2 class="mb-2 font-semibold">Admin job control</h2>
            <p class="mb-3 text-sm text-muted-foreground">
                Queue this Paperless document for event-driven reprocessing.
            </p>
            <Form
                method="post"
                action={suggestion.reprocess_url}
                class="flex gap-3"
            >
                {#snippet children({ processing })}
                    <input
                        type="hidden"
                        name="reason"
                        value="manual_admin_reprocess"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        Reprocess document
                    </Button>
                {/snippet}
            </Form>
        </section>
    {/if}

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
</div>
