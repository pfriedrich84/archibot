<script module lang="ts">
    import { index as operationsLogIndex } from '@/routes/operations-log';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Operations Log',
                href: operationsLogIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';

    type CommandEntry = {
        id: number;
        type: string;
        status: string;
        error: string | null;
        created_at: string | null;
    };

    type PipelineRunEntry = {
        id: number;
        type: string;
        status: string;
        paperless_document_id: number | null;
        progress_current_phase: string | null;
        progress_message: string | null;
        created_at: string | null;
        show_url: string;
    };

    type PipelineEventEntry = {
        id: number;
        event_type: string;
        level: string;
        message: string | null;
        created_at: string | null;
    };

    type ActorExecutionEntry = {
        id: number;
        actor_name: string;
        status: string;
        progress_message: string | null;
        error_message: string | null;
        created_at: string | null;
    };

    type WebhookDeliveryEntry = {
        id: number;
        event_type: string;
        status: string;
        paperless_document_id: number | null;
        received_at: string | null;
        error: string | null;
        show_url: string;
    };

    type AuditLogEntry = {
        id: number;
        event: string;
        target_type: string | null;
        target_id: string | null;
        created_at: string | null;
    };

    type Row = {
        time: string;
        label: string;
        href?: string;
        status: string;
        message: string;
    };

    type Section = {
        title: string;
        headers: [string, string, string, string];
        rows: Row[];
    };

    let {
        commands,
        pipelineRuns,
        pipelineEvents,
        actorExecutions,
        webhookDeliveries,
        auditLogs,
    }: {
        commands: CommandEntry[];
        pipelineRuns: PipelineRunEntry[];
        pipelineEvents: PipelineEventEntry[];
        actorExecutions: ActorExecutionEntry[];
        webhookDeliveries: WebhookDeliveryEntry[];
        auditLogs: AuditLogEntry[];
    } = $props();

    const sections: Section[] = $derived([
        {
            title: 'Recent commands',
            headers: ['Time', 'Command', 'Status', 'Error'],
            rows: commands.map((command) => ({
                time: formatDateTime(command.created_at, '-'),
                label: `Command #${command.id}: ${command.type}`,
                status: command.status,
                message: command.error ?? '-',
            })),
        },
        {
            title: 'Recent pipeline runs',
            headers: ['Time', 'Run', 'Status', 'Progress'],
            rows: pipelineRuns.map((run) => ({
                time: formatDateTime(run.created_at, '-'),
                label: `Run #${run.id}: ${run.type}${run.paperless_document_id ? ` doc #${run.paperless_document_id}` : ''}`,
                href: run.show_url,
                status: run.status,
                message:
                    run.progress_message ?? run.progress_current_phase ?? '-',
            })),
        },
        {
            title: 'Recent actor executions',
            headers: ['Time', 'Actor', 'Status', 'Message'],
            rows: actorExecutions.map((actor) => ({
                time: formatDateTime(actor.created_at, '-'),
                label: `Actor #${actor.id}: ${actor.actor_name}`,
                status: actor.status,
                message: actor.error_message ?? actor.progress_message ?? '-',
            })),
        },
        {
            title: 'Recent pipeline events',
            headers: ['Time', 'Event', 'Level', 'Message'],
            rows: pipelineEvents.map((event) => ({
                time: formatDateTime(event.created_at, '-'),
                label: `Event #${event.id}: ${event.event_type}`,
                status: event.level,
                message: event.message ?? '-',
            })),
        },
        {
            title: 'Recent webhook deliveries',
            headers: ['Time', 'Delivery', 'Status', 'Error'],
            rows: webhookDeliveries.map((delivery) => ({
                time: formatDateTime(delivery.received_at, '-'),
                label: `Delivery #${delivery.id}: ${delivery.event_type}${delivery.paperless_document_id ? ` doc #${delivery.paperless_document_id}` : ''}`,
                href: delivery.show_url,
                status: delivery.status,
                message: delivery.error ?? '-',
            })),
        },
        {
            title: 'Recent audit events',
            headers: ['Time', 'Event', 'Target', 'ID'],
            rows: auditLogs.map((log) => ({
                time: formatDateTime(log.created_at, '-'),
                label: log.event,
                status: log.target_type ?? '-',
                message: log.target_id ?? '-',
            })),
        },
    ]);
</script>

<AppHead title="Operations Log" />

<div class="space-y-6">
    <Heading
        title="Operations Log"
        description="Durable command, pipeline, actor, webhook and audit history."
    />

    {#each sections as section (section.title)}
        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-semibold">{section.title}</h2>
            {#if section.rows.length === 0}
                <p class="text-sm text-muted-foreground">No records yet.</p>
            {:else}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                {#each section.headers as header (header)}
                                    <th class="py-2 pr-3">{header}</th>
                                {/each}
                            </tr>
                        </thead>
                        <tbody>
                            {#each section.rows as row (`${row.label}-${row.time}`)}
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pr-3">{row.time}</td>
                                    <td class="max-w-md truncate py-2 pr-3">
                                        {#if row.href}
                                            <a class="underline" href={row.href}
                                                >{row.label}</a
                                            >
                                        {:else}
                                            {row.label}
                                        {/if}
                                    </td>
                                    <td class="py-2 pr-3">{row.status}</td>
                                    <td class="max-w-xl truncate py-2 pr-3">
                                        {row.message}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </section>
    {/each}
</div>
