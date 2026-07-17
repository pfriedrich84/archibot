<script module lang="ts">
    import { index as auditLogsIndex } from '@/routes/admin/audit-logs';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Audit logs',
                href: auditLogsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { formatDateTime } from '@/lib/datetime';
    import { formatDisplayValue, formatTarget } from '@/lib/display';

    type AuditLog = {
        id: number;
        event: string;
        target_type: string | null;
        target_id: string | null;
        target_url: string | null;
        metadata: {
            key: string;
            label: string;
            value: boolean | number | string | null;
        }[];
        actor: {
            id: number;
            name: string;
            paperless_username: string | null;
        } | null;
        created_at: string | null;
    };

    let { logs }: { logs: AuditLog[] } = $props();
</script>

<AppHead title="Audit logs" />

<div class="space-y-6">
    <Heading
        title="Audit logs"
        description="Recent sensitive ArchiBot setup, authentication, and settings events."
    />

    <div class="overflow-hidden rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/50 text-muted-foreground">
                <tr>
                    <th class="px-4 py-3 font-medium">Time</th>
                    <th class="px-4 py-3 font-medium">Event</th>
                    <th class="px-4 py-3 font-medium">Actor</th>
                    <th class="px-4 py-3 font-medium">Target</th>
                    <th class="px-4 py-3 font-medium">Metadata</th>
                </tr>
            </thead>
            <tbody>
                {#each logs as log (log.id)}
                    <tr class="border-t align-top">
                        <td class="px-4 py-3 whitespace-nowrap">
                            {formatDateTime(log.created_at)}
                        </td>
                        <td class="px-4 py-3 font-medium">{log.event}</td>
                        <td class="px-4 py-3">
                            {#if log.actor}
                                <div>{log.actor.name}</div>
                                <div class="text-muted-foreground">
                                    {log.actor.paperless_username ??
                                        'Local administrator'}
                                </div>
                            {:else}
                                <span class="text-muted-foreground">System</span
                                >
                            {/if}
                        </td>
                        <td class="px-4 py-3">
                            {#if log.target_url}
                                <a class="underline" href={log.target_url}>
                                    {formatTarget(
                                        log.target_type,
                                        log.target_id,
                                    )}
                                </a>
                            {:else}
                                {formatTarget(log.target_type, log.target_id)}
                            {/if}
                        </td>
                        <td class="px-4 py-3">
                            {#if log.metadata.length > 0}
                                <dl class="grid gap-1 text-xs">
                                    {#each log.metadata as entry (entry.key)}
                                        <div
                                            class="grid gap-1 sm:grid-cols-[8rem_1fr]"
                                        >
                                            <dt class="text-muted-foreground">
                                                {entry.label}
                                            </dt>
                                            <dd class="break-words">
                                                {formatDisplayValue(
                                                    entry.value,
                                                    entry.key,
                                                )}
                                            </dd>
                                        </div>
                                    {/each}
                                </dl>
                            {:else}
                                <span class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            {/if}
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td
                            class="px-4 py-8 text-center text-muted-foreground"
                            colspan="5"
                        >
                            No audit events yet.
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
