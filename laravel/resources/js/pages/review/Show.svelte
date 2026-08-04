<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import StatusBadge from '@/components/StatusBadge.svelte';
    import { Button } from '@/components/ui/button';
    import { csrfToken } from '@/lib/csrf';
    import { formatDate } from '@/lib/datetime';
    import { numericId, paperlessLabel } from '@/lib/paperless';
    import type { PaperlessEntityOption } from '@/lib/paperless';
    import { accept, reject } from '@/routes/review';

    type EntityOption = PaperlessEntityOption;

    type Suggestion = {
        id: number;
        paperless_document_id: number;
        status: string;
        confidence: number | null;
        reasoning: string | null;
        commit_status: string | null;
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

    let selectedCorrespondentId = $state<number | ''>(
        untrack(() => numericId(suggestion.proposed.correspondent_id) ?? ''),
    );
    let selectedDocumentTypeId = $state<number | ''>(
        untrack(() => numericId(suggestion.proposed.document_type_id) ?? ''),
    );
    let selectedStoragePathId = $state<number | ''>(
        untrack(
            () =>
                numericId(
                    suggestion.proposed.storage_path_id ??
                        suggestion.original.storage_path_id,
                ) ?? '',
        ),
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

    const dateValue = (value: unknown): string =>
        formatDate(typeof value === 'string' ? value : null);

    const entityValue = (
        idValue: unknown,
        nameValue: unknown,
        options: EntityOption[],
    ): string => paperlessLabel(idValue, nameValue, options);

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

    const comparisonRows = $derived([
        {
            label: 'Title',
            original: textValue(suggestion.original.title),
            proposed: textValue(suggestion.proposed.title),
        },
        {
            label: 'Date',
            original: dateValue(suggestion.original.date),
            proposed: dateValue(suggestion.proposed.date),
        },
        {
            label: 'Correspondent',
            original: entityValue(
                suggestion.original.correspondent_id,
                suggestion.original.correspondent_name,
                entityOptions.correspondents,
            ),
            proposed: entityValue(
                suggestion.proposed.correspondent_id,
                suggestion.proposed.correspondent_name,
                entityOptions.correspondents,
            ),
        },
        {
            label: 'Document type',
            original: entityValue(
                suggestion.original.document_type_id,
                suggestion.original.document_type_name,
                entityOptions.documentTypes,
            ),
            proposed: entityValue(
                suggestion.proposed.document_type_id,
                suggestion.proposed.document_type_name,
                entityOptions.documentTypes,
            ),
        },
        {
            label: 'Storage path',
            original: entityValue(
                suggestion.original.storage_path_id,
                suggestion.original.storage_path_name,
                entityOptions.storagePaths,
            ),
            proposed: entityValue(
                suggestion.proposed.storage_path_id,
                suggestion.proposed.storage_path_name,
                entityOptions.storagePaths,
            ),
        },
        {
            label: 'Tags',
            original: tagValues(suggestion.original.tags),
            proposed: tagValues(suggestion.proposed.tags),
        },
    ]);

    const documentTitle = $derived(
        textValue(suggestion.proposed.title ?? suggestion.original.title),
    );
    const changedCount = $derived(
        comparisonRows.filter((row) => row.original !== row.proposed).length,
    );
    const isAdmin = $derived(Boolean(page.props.auth.user?.is_admin));
</script>

<AppHead title={`Review document ${suggestion.paperless_document_id}`} />

<div class="space-y-6">
    <Heading
        title={documentTitle === '—'
            ? `Document ${suggestion.paperless_document_id}`
            : documentTitle}
        description={`Paperless document ${suggestion.paperless_document_id} · Review ${changedCount} proposed ${changedCount === 1 ? 'change' : 'changes'} before anything is queued for Paperless.`}
    />

    <div class="flex flex-wrap items-center gap-2" aria-label="Review status">
        <StatusBadge status={suggestion.status} />
        {#if suggestion.commit_status}
            <StatusBadge
                status={suggestion.commit_status}
                label={`Paperless update: ${suggestion.commit_status}`}
            />
        {/if}
        {#if suggestion.confidence !== null}
            <span
                class="inline-flex min-h-7 items-center rounded-full border bg-card px-2.5 py-1 text-xs font-medium"
            >
                {suggestion.confidence}% model confidence
            </span>
        {/if}
        {#if suggestion.judge_verdict}
            <span
                class="inline-flex min-h-7 items-center rounded-full border bg-card px-2.5 py-1 text-xs font-medium"
            >
                Judge: {suggestion.judge_verdict}
            </span>
        {/if}
    </div>

    <div
        class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(24rem,0.8fr)]"
    >
        <section class="register-panel overflow-hidden xl:sticky xl:top-20">
            <div
                class="flex items-center justify-between gap-3 border-b px-4 py-3 sm:px-5"
            >
                <div>
                    <h2 class="font-semibold">Document preview</h2>
                    <p class="text-sm text-muted-foreground">
                        Keep the source visible while checking each change.
                    </p>
                </div>
                <a
                    class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                    href={suggestion.preview_url}
                    target="_blank"
                    rel="noreferrer"
                >
                    Open separately
                </a>
            </div>
            <iframe
                title={`Preview document ${suggestion.paperless_document_id}`}
                src={suggestion.preview_url}
                class="h-[30vh] min-h-[14rem] w-full bg-white sm:h-[45vh] sm:min-h-[22rem] xl:h-[68vh] xl:min-h-[32rem]"
            ></iframe>
        </section>

        <div class="space-y-4">
            <section class="register-panel" aria-labelledby="changes-heading">
                <div class="border-b px-4 py-3 sm:px-5">
                    <h2 id="changes-heading" class="font-semibold">
                        Proposed changes
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Changed fields are marked; unchanged context stays
                        quiet.
                    </p>
                </div>
                <dl>
                    {#each comparisonRows as row (row.label)}
                        <div
                            class="register-ledger-row grid gap-1 px-4 py-3 sm:grid-cols-[8rem_1fr] sm:gap-4 sm:px-5 {row.original !==
                            row.proposed
                                ? 'bg-primary/[0.045]'
                                : ''}"
                        >
                            <dt class="text-sm font-medium">{row.label}</dt>
                            <dd class="min-w-0 text-sm">
                                {#if row.original !== row.proposed}
                                    <div
                                        class="break-words font-medium text-foreground"
                                    >
                                        {row.proposed}
                                    </div>
                                    <div
                                        class="mt-1 break-words text-xs text-muted-foreground line-through decoration-border"
                                    >
                                        {row.original}
                                    </div>
                                    <span class="sr-only">Changed</span>
                                {:else}
                                    <div
                                        class="break-words text-muted-foreground"
                                    >
                                        {row.proposed}
                                    </div>
                                {/if}
                            </dd>
                        </div>
                    {/each}
                </dl>
            </section>

            {#if suggestion.status === 'pending'}
                <details class="register-disclosure">
                    <summary>Edit proposed metadata</summary>
                    <Form
                        method="post"
                        action={suggestion.save_url}
                        class="grid gap-4 border-t p-4 sm:grid-cols-2 sm:p-5"
                    >
                        {#snippet children({ processing })}
                            <input
                                type="hidden"
                                name="_token"
                                value={csrfToken()}
                            />
                            <label
                                class="grid gap-1.5 text-sm font-medium sm:col-span-2"
                            >
                                Title
                                <input
                                    name="proposed_title"
                                    value={String(
                                        suggestion.proposed.title ?? '',
                                    )}
                                    class="h-11 rounded-md border bg-background px-3"
                                />
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Date
                                <input
                                    name="proposed_date"
                                    type="date"
                                    value={String(
                                        suggestion.proposed.date ?? '',
                                    )}
                                    class="h-11 rounded-md border bg-background px-3"
                                />
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Correspondent
                                <select
                                    name="proposed_correspondent_id"
                                    bind:value={selectedCorrespondentId}
                                    class="h-11 rounded-md border bg-background px-3"
                                >
                                    <option value="">No correspondent</option>
                                    {#each entityOptions.correspondents as option (option.id)}
                                        <option value={option.id}>
                                            {paperlessLabel(
                                                option.id,
                                                option.name,
                                            )}
                                        </option>
                                    {/each}
                                </select>
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Correspondent name
                                <input
                                    name="proposed_correspondent_name"
                                    value={String(
                                        suggestion.proposed
                                            .correspondent_name ?? '',
                                    )}
                                    class="h-11 rounded-md border bg-background px-3"
                                />
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Document type
                                <select
                                    name="proposed_document_type_id"
                                    bind:value={selectedDocumentTypeId}
                                    class="h-11 rounded-md border bg-background px-3"
                                >
                                    <option value="">No document type</option>
                                    {#each entityOptions.documentTypes as option (option.id)}
                                        <option value={option.id}>
                                            {paperlessLabel(
                                                option.id,
                                                option.name,
                                            )}
                                        </option>
                                    {/each}
                                </select>
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Document type name
                                <input
                                    name="proposed_document_type_name"
                                    value={String(
                                        suggestion.proposed
                                            .document_type_name ?? '',
                                    )}
                                    class="h-11 rounded-md border bg-background px-3"
                                />
                            </label>
                            <label class="grid gap-1.5 text-sm font-medium">
                                Storage path
                                <select
                                    name="proposed_storage_path_id"
                                    bind:value={selectedStoragePathId}
                                    class="h-11 rounded-md border bg-background px-3"
                                >
                                    <option value="">No storage path</option>
                                    {#each entityOptions.storagePaths as option (option.id)}
                                        <option value={option.id}>
                                            {paperlessLabel(
                                                option.id,
                                                option.name,
                                            )}
                                        </option>
                                    {/each}
                                </select>
                                <input
                                    type="hidden"
                                    name="proposed_storage_path_name"
                                    value={selectedStoragePathName}
                                />
                            </label>
                            <div class="sm:col-span-2">
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}>Save changes</Button
                                >
                            </div>
                        {/snippet}
                    </Form>
                </details>

                <section
                    class="register-panel sticky bottom-3 z-10 p-4 shadow-[0_18px_44px_-24px_hsl(162_17%_11%/0.55)] sm:p-5"
                    aria-labelledby="decision-heading"
                >
                    <h2 id="decision-heading" class="font-semibold">
                        Decide this review
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        Accept queues the reviewed metadata update in Paperless.
                        Reject leaves Paperless unchanged.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <Form
                            {...accept.form(suggestion.id)}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        'Accept this suggestion and queue its reviewed metadata update in Paperless?',
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken()}
                                />
                                <Button type="submit" disabled={processing}>
                                    Accept and review next
                                </Button>
                            {/snippet}
                        </Form>
                        <Form
                            {...reject.form(suggestion.id)}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        'Reject this suggestion? No Paperless metadata will be changed.',
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken()}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Reject and review next
                                </Button>
                            {/snippet}
                        </Form>
                    </div>
                </section>
            {/if}

            <details class="register-disclosure">
                <summary>Decision evidence</summary>
                <div class="space-y-5 border-t p-4 text-sm leading-6 sm:p-5">
                    <section aria-labelledby="classification-reasoning">
                        <h2 id="classification-reasoning" class="font-semibold">
                            Classification reasoning
                        </h2>
                        <p
                            class="mt-1 whitespace-pre-wrap text-muted-foreground"
                        >
                            {suggestion.reasoning ??
                                'No classification reasoning was recorded for this suggestion.'}
                        </p>
                    </section>
                    <section aria-labelledby="judge-reasoning">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="judge-reasoning" class="font-semibold">
                                Judge reasoning
                            </h2>
                            {#if suggestion.judge_verdict}
                                <span class="text-xs text-muted-foreground">
                                    {suggestion.judge_verdict}
                                </span>
                            {/if}
                        </div>
                        <p
                            class="mt-1 whitespace-pre-wrap text-muted-foreground"
                        >
                            {suggestion.judge_reasoning ??
                                'No judge reasoning was recorded for this suggestion.'}
                        </p>
                    </section>
                </div>
            </details>

            {#if isAdmin}
                <details class="register-disclosure">
                    <summary>Admin controls</summary>
                    <div class="border-t p-4 sm:p-5">
                        <p class="mb-3 text-sm leading-6 text-muted-foreground">
                            Force a fresh pipeline run for this document even
                            when its content is unchanged.
                        </p>
                        <Form
                            method="post"
                            action={suggestion.reprocess_url}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        'Force a new pipeline run for this one document? This queues fresh processing even when its content is unchanged.',
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken()}
                                />
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
                    </div>
                </details>
            {/if}
        </div>
    </div>
</div>
