<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Pipeline runs',
                href: '/pipeline-runs',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';

    type LinkedCommand = {
        id: number;
        type: string;
        status: string;
        created_at: string | null;
    };

    type LinkedWebhookDelivery = {
        id: number;
        source: string;
        event_type: string;
        status: string;
        paperless_document_id: number | null;
        show_url: string;
    };

    type PipelineRun = {
        id: number;
        type: string;
        status: string;
        scope: string | null;
        trigger_source: string;
        paperless_document_id: number | null;
        progress_total: number;
        progress_done: number;
        progress_failed: number;
        progress_skipped: number;
        progress_current_phase: string | null;
        progress_message: string | null;
        updated_at: string | null;
        events_count: number;
        items_count: number;
        show_url: string;
        retry_url: string;
        retry_failed_items_url: string;
        cancel_url: string;
        can_retry: boolean;
        can_retry_failed_items: boolean;
        can_cancel: boolean;
        command: LinkedCommand | null;
        webhook_delivery: LinkedWebhookDelivery | null;
    };

    type Paginator<T> = {
        data: T[];
        total: number;
    };

    let {
        runs,
        isAdmin,
    }: {
        runs: Paginator<PipelineRun>;
        isAdmin: boolean;
    } = $props();
</script>

<AppHead title="Pipeline runs" />

<div class="space-y-6">
    <Heading
        title="Pipeline runs"
        description="Durable pipeline visibility for commands, webhooks, progress, events, and actor executions."
    />

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {runs.total} pipeline run{runs.total === 1 ? '' : 's'}
        </div>

        {#each runs.data as run (run.id)}
            <div class="grid gap-3 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        class="font-semibold underline-offset-4 hover:underline"
                        href={run.show_url}
                    >
                        Pipeline run {run.id} · {run.type}
                    </a>
                    <span class="rounded-full bg-muted px-2 py-0.5">
                        {run.status}
                    </span>
                    <span class="text-muted-foreground">
                        trigger {run.trigger_source}
                    </span>
                    {#if run.scope}
                        <span class="text-muted-foreground"
                            >scope {run.scope}</span
                        >
                    {/if}
                    {#if run.paperless_document_id}
                        <span class="text-muted-foreground">
                            document {run.paperless_document_id}
                        </span>
                    {/if}
                </div>

                <div class="grid gap-2 text-muted-foreground md:grid-cols-2">
                    <div>
                        Progress: {run.progress_done}/{run.progress_total} done ·
                        {run.progress_failed} failed · {run.progress_skipped} skipped
                    </div>
                    <div>Phase: {run.progress_current_phase ?? '—'}</div>
                    <div>Events: {run.events_count}</div>
                    <div>Items: {run.items_count}</div>
                    <div>Updated: {formatDateTime(run.updated_at)}</div>
                    <div>
                        Command: {#if run.command}#{run.command.id} · {run
                                .command.type} · {run.command
                                .status}{:else}—{/if}
                    </div>
                    <div>
                        Webhook: {#if run.webhook_delivery}<a
                                class="underline-offset-4 hover:underline"
                                href={run.webhook_delivery.show_url}
                                >#{run.webhook_delivery.id} · {run
                                    .webhook_delivery.event_type} · {run
                                    .webhook_delivery.status}</a
                            >{:else}—{/if}
                    </div>
                </div>

                {#if run.progress_message}
                    <div
                        class="rounded-md bg-muted/50 p-3 text-muted-foreground"
                    >
                        {run.progress_message}
                    </div>
                {/if}

                {#if isAdmin && (run.can_retry || run.can_retry_failed_items || run.can_cancel)}
                    <div class="flex flex-wrap gap-2">
                        {#if run.can_retry}
                            <Form method="post" action={run.retry_url}>
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                        disabled={processing}
                                    >
                                        Retry run
                                    </button>
                                {/snippet}
                            </Form>
                        {/if}
                        {#if run.can_retry_failed_items}
                            <Form
                                method="post"
                                action={run.retry_failed_items_url}
                            >
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                        disabled={processing}
                                    >
                                        Retry failed items
                                    </button>
                                {/snippet}
                            </Form>
                        {/if}
                        {#if run.can_cancel}
                            <Form method="post" action={run.cancel_url}>
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                        disabled={processing}
                                    >
                                        Cancel run
                                    </button>
                                {/snippet}
                            </Form>
                        {/if}
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No pipeline runs yet.
            </div>
        {/each}
    </div>
</div>
