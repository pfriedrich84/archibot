<script module lang="ts">
    import { index as webhookDeliveriesIndex } from '@/routes/webhook-deliveries';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Webhook deliveries',
                href: webhookDeliveriesIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import Pagination from '@/components/Pagination.svelte';
    import { formatDateTime } from '@/lib/datetime';
    import { formatDisplayValue } from '@/lib/display';
    import type { Paginator } from '@/types';

    type SummaryEntry = {
        key: string;
        label: string;
        value: boolean | number | string | null;
    };

    type WebhookDelivery = {
        id: number;
        source: string;
        event_type: string;
        paperless_document_id: number | null;
        status: string;
        dedupe_key: string | null;
        payload_hash: string | null;
        request_id: string | null;
        received_at: string | null;
        processed_at: string | null;
        error: string | null;
        payload_summary: SummaryEntry[];
        show_url: string;
        retry_url: string;
        dismiss_url: string;
        can_retry: boolean;
        can_dismiss: boolean;
    };

    let {
        deliveries,
        isAdmin,
    }: {
        deliveries: Paginator<WebhookDelivery>;
        isAdmin: boolean;
    } = $props();
</script>

<AppHead title="Webhook deliveries" />

<div class="space-y-6">
    <Heading
        title="Webhook deliveries"
        description="Inspect Paperless webhook deliveries, payload summaries, and failure controls."
    />

    <div class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {deliveries.total} webhook deliver{deliveries.total === 1
                ? 'y'
                : 'ies'}
        </div>

        {#each deliveries.data as delivery (delivery.id)}
            <div class="grid gap-3 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        class="font-semibold underline-offset-4 hover:underline"
                        href={delivery.show_url}
                    >
                        Webhook {delivery.id} · {delivery.event_type}
                    </a>
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

                <div class="grid gap-2 text-muted-foreground md:grid-cols-2">
                    <div>Received: {formatDateTime(delivery.received_at)}</div>
                    <div>
                        Processed: {formatDateTime(delivery.processed_at)}
                    </div>
                    <div class="break-all">
                        Dedupe: {delivery.dedupe_key ?? '—'}
                    </div>
                    <div class="break-all">
                        Request: {delivery.request_id ?? '—'}
                    </div>
                </div>

                {#if delivery.error}
                    <div
                        class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-destructive"
                    >
                        {delivery.error}
                    </div>
                {/if}

                {#if delivery.payload_summary.length}
                    <dl
                        class="grid gap-2 rounded-md bg-muted/50 p-3 text-xs md:grid-cols-2"
                    >
                        {#each delivery.payload_summary as entry (entry.key)}
                            <div>
                                <dt class="font-medium">{entry.label}</dt>
                                <dd class="break-all text-muted-foreground">
                                    {formatDisplayValue(entry.value, entry.key)}
                                </dd>
                            </div>
                        {/each}
                    </dl>
                {/if}

                {#if isAdmin && (delivery.can_retry || delivery.can_dismiss)}
                    <div class="flex flex-wrap gap-2">
                        {#if delivery.can_retry}
                            <Form method="post" action={delivery.retry_url}>
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                        disabled={processing}
                                    >
                                        Retry webhook delivery
                                    </button>
                                {/snippet}
                            </Form>
                        {/if}
                        {#if delivery.can_dismiss}
                            <Form
                                method="post"
                                action={delivery.dismiss_url}
                                onsubmit={(event) => {
                                    if (
                                        !confirm(
                                            `Dismiss webhook failure ${delivery.id}? It will no longer appear as an active failure.`,
                                        )
                                    ) {
                                        event.preventDefault();
                                    }
                                }}
                            >
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        class="rounded-md border px-3 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                        disabled={processing}
                                    >
                                        Dismiss webhook failure
                                    </button>
                                {/snippet}
                            </Form>
                        {/if}
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No webhook deliveries yet.
            </div>
        {/each}
        <Pagination
            links={deliveries.links}
            from={deliveries.from}
            to={deliveries.to}
            total={deliveries.total}
            perPage={deliveries.per_page}
            label="Webhook delivery pages"
        />
    </div>
</div>
