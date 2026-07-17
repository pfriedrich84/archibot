<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Webhook deliveries',
                href: '/webhook-deliveries',
            },
            {
                title: 'Delivery detail',
                href: '#',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';
    import { formatDisplayValue } from '@/lib/display';

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
        pipeline_events: {
            id: number;
            event_type: string;
            level: string;
            message: string | null;
            paperless_document_id: number | null;
            pipeline_run_id: number | null;
            command_id: number | null;
            metadata: SummaryEntry[];
            created_at: string | null;
        }[];
        retry_url: string;
        dismiss_url: string;
        can_retry: boolean;
        can_dismiss: boolean;
    };

    let {
        delivery,
        isAdmin,
    }: {
        delivery: WebhookDelivery;
        isAdmin: boolean;
    } = $props();
</script>

<AppHead title={`Webhook delivery ${delivery.id}`} />

<div class="space-y-6">
    <Heading
        title={`Webhook delivery ${delivery.id}`}
        description={`${delivery.source} · ${delivery.event_type}`}
    />

    <section class="rounded-xl border p-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-muted px-2 py-0.5 text-sm">
                {delivery.status}
            </span>
            {#if delivery.paperless_document_id}
                <span class="text-sm text-muted-foreground">
                    document {delivery.paperless_document_id}
                </span>
            {/if}
            {#if isAdmin && (delivery.can_retry || delivery.can_dismiss)}
                <div class="ml-auto flex flex-wrap gap-2">
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
                        <Form method="post" action={delivery.dismiss_url}>
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

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Dedupe key</dt>
                <dd class="break-all font-medium">
                    {delivery.dedupe_key ?? '—'}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Payload hash</dt>
                <dd class="break-all font-medium">
                    {delivery.payload_hash ?? '—'}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Request ID</dt>
                <dd class="break-all font-medium">
                    {delivery.request_id ?? '—'}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Received</dt>
                <dd class="font-medium">
                    {formatDateTime(delivery.received_at)}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Processed</dt>
                <dd class="font-medium">
                    {formatDateTime(delivery.processed_at)}
                </dd>
            </div>
        </dl>

        {#if delivery.error}
            <div
                class="mt-4 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
            >
                {delivery.error}
            </div>
        {/if}
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Pipeline events</h2>
        <div class="space-y-2 text-sm">
            {#each delivery.pipeline_events as event (event.id)}
                <div class="rounded-md bg-muted/40 p-3">
                    <div class="flex flex-wrap gap-2 text-muted-foreground">
                        <span>{formatDateTime(event.created_at)}</span>
                        <span>[{event.level}]</span>
                        <span>{event.event_type}</span>
                        {#if event.pipeline_run_id}<span
                                >run {event.pipeline_run_id}</span
                            >{/if}
                        {#if event.command_id}<span
                                >command {event.command_id}</span
                            >{/if}
                        {#if event.paperless_document_id}<span
                                >document {event.paperless_document_id}</span
                            >{/if}
                    </div>
                    {#if event.message}
                        <div class="mt-1 break-words">{event.message}</div>
                    {/if}
                    {#if event.metadata.length > 0}
                        <dl class="mt-2 grid gap-1 text-xs sm:grid-cols-2">
                            {#each event.metadata as entry (entry.key)}
                                <div>
                                    <dt class="text-muted-foreground">
                                        {entry.label}
                                    </dt>
                                    <dd>
                                        {formatDisplayValue(
                                            entry.value,
                                            entry.key,
                                        )}
                                    </dd>
                                </div>
                            {/each}
                        </dl>
                    {/if}
                </div>
            {:else}
                <div class="text-muted-foreground">
                    No pipeline events are linked to this delivery.
                </div>
            {/each}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Delivery metadata</h2>
        {#if delivery.payload_summary.length > 0}
            <dl class="grid gap-3 text-sm md:grid-cols-2">
                {#each delivery.payload_summary as entry (entry.key)}
                    <div>
                        <dt class="text-muted-foreground">{entry.label}</dt>
                        <dd class="font-medium">
                            {formatDisplayValue(entry.value, entry.key)}
                        </dd>
                    </div>
                {/each}
            </dl>
        {:else}
            <p class="text-sm text-muted-foreground">
                No allowlisted delivery metadata was recorded. Raw payloads and
                headers are intentionally not displayed.
            </p>
        {/if}
    </section>
</div>
