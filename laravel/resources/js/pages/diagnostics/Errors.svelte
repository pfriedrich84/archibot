<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Errors', href: '/errors' }],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { formatDateTime } from '@/lib/datetime';
    import { displayEntries } from '@/lib/display';
    import { index as errorsIndex } from '@/routes/errors';

    type LegacyError = {
        id: number;
        occurred_at: string | null;
        stage: string | null;
        document_reference: number | null;
        message: string;
        details: Record<string, unknown> | null;
    };

    type FailedJob = {
        id: number;
        type: string;
        status: string;
        error: string | null;
        payload: Record<string, unknown>;
        progress: Record<string, unknown>;
        result: Record<string, unknown>;
        exit_code: number | null;
        created_at: string | null;
        started_at: string | null;
        finished_at: string | null;
        show_url: string;
        retry_url: string | null;
        can_retry: boolean;
        can_retry_failed_only: boolean;
    };

    type WebhookError = {
        id: number;
        source: string;
        event_type: string;
        paperless_document_id: number | null;
        status: string;
        dedupe_key: string | null;
        request_id: string | null;
        received_at: string | null;
        processed_at: string | null;
        error: string | null;
        payload_summary: { key: string; value: unknown }[];
        show_url: string;
        retry_url: string | null;
        dismiss_url: string | null;
        can_retry: boolean;
        can_dismiss: boolean;
    };

    type PaginatorLink = {
        url: string | null;
        label: string;
        active: boolean;
    };

    type Paginator<T> = {
        data: T[];
        total: number;
        links: PaginatorLink[];
    };

    let {
        filters,
        filterOptions,
        failedJobs,
        webhookErrors,
        legacyErrors,
        isAdmin,
    }: {
        filters: { source: string; status: string };
        filterOptions: { sources: string[]; statuses: string[] };
        failedJobs: Paginator<FailedJob>;
        webhookErrors: Paginator<WebhookError>;
        legacyErrors: LegacyError[];
        isAdmin: boolean;
    } = $props();

    const label = (value: string) =>
        value
            .replaceAll('_', ' ')
            .replace(/^./, (character) => character.toUpperCase());

    const formatValue = (value: unknown) =>
        typeof value === 'string' ? value : JSON.stringify(value);

    const paginationLabel = (value: string) =>
        value.replace('&laquo;', '‹').replace('&raquo;', '›');
</script>

<AppHead title="Errors" />

<div class="space-y-6">
    <Heading
        title="Errors"
        description="Diagnose failed worker jobs, blocked webhook deliveries, and recent legacy Python errors from one operations page."
    />

    <form
        method="get"
        action={errorsIndex().url}
        class="grid gap-3 rounded-xl border p-4 text-sm md:grid-cols-[1fr_1fr_auto]"
    >
        <label class="grid gap-1">
            <span class="font-medium">Source</span>
            <select
                name="source"
                class="rounded-md border bg-background px-3 py-2"
            >
                {#each filterOptions.sources as source (source)}
                    <option value={source} selected={filters.source === source}>
                        {label(source)}
                    </option>
                {/each}
            </select>
        </label>
        <label class="grid gap-1">
            <span class="font-medium">Status</span>
            <select
                name="status"
                class="rounded-md border bg-background px-3 py-2"
            >
                {#each filterOptions.statuses as status (status)}
                    <option value={status} selected={filters.status === status}>
                        {label(status)}
                    </option>
                {/each}
            </select>
        </label>
        <div class="flex items-end gap-2">
            <Button type="submit">Apply filters</Button>
            <a class="rounded-md border px-3 py-2" href={errorsIndex().url}
                >Reset</a
            >
        </div>
    </form>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {failedJobs.total} worker diagnostic event{failedJobs.total === 1
                ? ''
                : 's'}
        </div>

        {#each failedJobs.data as job (job.id)}
            <article class="space-y-3 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        class="font-medium underline-offset-4 hover:underline"
                        href={job.show_url}>Worker job {job.id} · {job.type}</a
                    >
                    <span class="rounded-full bg-muted px-2 py-0.5"
                        >{job.status}</span
                    >
                    {#if job.exit_code !== null}
                        <span class="text-muted-foreground"
                            >exit {job.exit_code}</span
                        >
                    {/if}
                    {#if job.finished_at}
                        <span class="text-muted-foreground"
                            >finished {formatDateTime(job.finished_at)}</span
                        >
                    {/if}
                </div>
                {#if job.error}
                    <p class="text-destructive">{job.error}</p>
                {/if}
                <dl class="grid gap-1 text-xs md:grid-cols-2">
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>{formatDateTime(job.created_at)}</dd>
                    </div>
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Started</dt>
                        <dd>{formatDateTime(job.started_at)}</dd>
                    </div>
                    {#each displayEntries(job.payload).slice(0, 4) as entry (entry.key)}
                        <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                            <dt class="text-muted-foreground">{entry.label}</dt>
                            <dd>{entry.value}</dd>
                        </div>
                    {/each}
                    {#each displayEntries(job.progress).slice(0, 4) as entry (entry.key)}
                        <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                            <dt class="text-muted-foreground">{entry.label}</dt>
                            <dd>{entry.value}</dd>
                        </div>
                    {/each}
                </dl>
                {#if isAdmin && job.can_retry && job.retry_url}
                    <div class="flex flex-wrap gap-2">
                        <Form method="post" action={job.retry_url}>
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    >Retry whole job</Button
                                >
                            {/snippet}
                        </Form>
                        {#if job.can_retry_failed_only}
                            <Form method="post" action={job.retry_url}>
                                {#snippet children({ processing })}
                                    <input
                                        type="hidden"
                                        name="failed_only"
                                        value="1"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        >Retry failed documents only</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                    </div>
                {/if}
            </article>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No worker errors match the current filters.
            </div>
        {/each}

        {#if failedJobs.links.length > 3}
            <nav class="flex flex-wrap gap-2 border-t p-4 text-sm">
                {#each failedJobs.links as link, index (`${link.label}-${link.url ?? index}`)}
                    {#if link.url}
                        <a
                            class:font-semibold={link.active}
                            class="rounded-md border px-3 py-1"
                            href={link.url}>{paginationLabel(link.label)}</a
                        >
                    {:else}
                        <span class="rounded-md border px-3 py-1 opacity-50"
                            >{paginationLabel(link.label)}</span
                        >
                    {/if}
                {/each}
            </nav>
        {/if}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {webhookErrors.total} webhook delivery error{webhookErrors.total ===
            1
                ? ''
                : 's'}
        </div>

        {#each webhookErrors.data as delivery (delivery.id)}
            <article class="space-y-3 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        class="font-medium underline-offset-4 hover:underline"
                        href={delivery.show_url}
                        >Webhook {delivery.id} · {delivery.event_type}</a
                    >
                    <span class="rounded-full bg-muted px-2 py-0.5">
                        {delivery.status}
                    </span>
                    <span class="text-muted-foreground">
                        source {delivery.source}
                    </span>
                    {#if delivery.paperless_document_id}
                        <span class="text-muted-foreground">
                            document {delivery.paperless_document_id}
                        </span>
                    {/if}
                </div>
                {#if delivery.error}
                    <p class="text-destructive">{delivery.error}</p>
                {/if}
                <dl class="grid gap-1 text-xs md:grid-cols-2">
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Received</dt>
                        <dd>{formatDateTime(delivery.received_at)}</dd>
                    </div>
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Processed</dt>
                        <dd>{formatDateTime(delivery.processed_at)}</dd>
                    </div>
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Dedupe</dt>
                        <dd class="break-all">{delivery.dedupe_key ?? '—'}</dd>
                    </div>
                    <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                        <dt class="text-muted-foreground">Request</dt>
                        <dd class="break-all">{delivery.request_id ?? '—'}</dd>
                    </div>
                    {#each delivery.payload_summary as entry (entry.key)}
                        <div class="grid gap-1 sm:grid-cols-[7rem_1fr]">
                            <dt class="text-muted-foreground">{entry.key}</dt>
                            <dd class="break-all">
                                {formatValue(entry.value)}
                            </dd>
                        </div>
                    {/each}
                </dl>
                {#if isAdmin && (delivery.can_retry || delivery.can_dismiss)}
                    <div class="flex flex-wrap gap-2">
                        {#if delivery.can_retry && delivery.retry_url}
                            <Form method="post" action={delivery.retry_url}>
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        >Retry webhook delivery</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                        {#if delivery.can_dismiss && delivery.dismiss_url}
                            <Form method="post" action={delivery.dismiss_url}>
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        >Dismiss webhook failure</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                    </div>
                {/if}
            </article>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No webhook delivery errors match the current filters.
            </div>
        {/each}

        {#if webhookErrors.links.length > 3}
            <nav class="flex flex-wrap gap-2 border-t p-4 text-sm">
                {#each webhookErrors.links as link, index (`${link.label}-${link.url ?? index}`)}
                    {#if link.url}
                        <a
                            class:font-semibold={link.active}
                            class="rounded-md border px-3 py-1"
                            href={link.url}>{paginationLabel(link.label)}</a
                        >
                    {:else}
                        <span class="rounded-md border px-3 py-1 opacity-50"
                            >{paginationLabel(link.label)}</span
                        >
                    {/if}
                {/each}
            </nav>
        {/if}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {legacyErrors.length} Python classifier error{legacyErrors.length ===
            1
                ? ''
                : 's'}
        </div>

        {#each legacyErrors as error (error.id)}
            <article class="space-y-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">
                        {error.stage ?? 'Classifier'} error
                    </span>
                    {#if error.document_reference}
                        <span class="text-muted-foreground">
                            document reference {error.document_reference}
                        </span>
                    {/if}
                    {#if error.occurred_at}
                        <span class="text-muted-foreground">
                            {formatDateTime(error.occurred_at)}
                        </span>
                    {/if}
                </div>
                <p class="text-destructive">{error.message}</p>
                {#if displayEntries(error.details).length > 0}
                    <dl class="grid gap-1 text-xs">
                        {#each displayEntries(error.details) as entry (entry.key)}
                            <div class="grid gap-1 sm:grid-cols-[10rem_1fr]">
                                <dt class="text-muted-foreground">
                                    {entry.label}
                                </dt>
                                <dd>{entry.value}</dd>
                            </div>
                        {/each}
                    </dl>
                {/if}
            </article>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No recent Python classifier errors match the current filters.
            </div>
        {/each}
    </section>
</div>
