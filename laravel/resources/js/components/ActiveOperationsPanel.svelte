<script lang="ts">
    import { formatDateTime } from '@/lib/datetime';

    type ActiveOperation = {
        key: string;
        kind: string;
        id: number;
        label: string;
        status: string;
        detail: string;
        progress_total: number;
        progress_done: number;
        progress_failed: number;
        progress_skipped: number;
        progress_message: string | null;
        created_at: string | null;
        started_at: string | null;
        updated_at: string | null;
        href: string;
    };

    type ActiveOperations = {
        summary: {
            total: number;
            queued: number;
            running: number;
            retrying: number;
            blocked: number;
        };
        items: ActiveOperation[];
        operations_log_url: string;
    };

    let { operations }: { operations: ActiveOperations } = $props();

    const statusClasses: Record<string, string> = {
        pending:
            'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
        queued: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
        running:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
        retrying:
            'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
        blocked:
            'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
        cancel_requested:
            'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-200',
    };

    function statusClass(status: string): string {
        return (
            statusClasses[status] ??
            'bg-muted text-muted-foreground dark:bg-muted dark:text-muted-foreground'
        );
    }

    function statusLabel(status: string): string {
        return status.replaceAll('_', ' ');
    }

    function progressValue(operation: ActiveOperation): number | null {
        if (operation.progress_total <= 0) {
            return null;
        }

        const completed =
            operation.progress_done +
            operation.progress_failed +
            operation.progress_skipped;

        return Math.min(
            100,
            Math.round((completed / operation.progress_total) * 100),
        );
    }

    function timeLabel(operation: ActiveOperation): string {
        if (operation.started_at) {
            return `Started ${formatDateTime(operation.started_at, '-')}`;
        }

        return `Queued ${formatDateTime(operation.created_at, '-')}`;
    }
</script>

<section class="rounded-xl border p-4">
    <div
        class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
    >
        <div>
            <h2 class="font-semibold">Active operations</h2>
            <p class="text-sm text-muted-foreground">
                {#if operations.summary.total === 0}
                    Nothing queued or running right now.
                {:else}
                    {operations.summary.total} active · {operations.summary
                        .running} running · {operations.summary.queued} queued
                    {#if operations.summary.retrying > 0}
                        · {operations.summary.retrying} retrying
                    {/if}
                    {#if operations.summary.blocked > 0}
                        · {operations.summary.blocked} blocked
                    {/if}
                {/if}
            </p>
        </div>
        <a class="text-sm underline" href={operations.operations_log_url}
            >Open operations log</a
        >
    </div>

    {#if operations.items.length === 0}
        <div
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            Start a Maintenance command, document pipeline, webhook retry, or
            reindex to see live status here.
        </div>
    {:else}
        <div class="space-y-3">
            {#each operations.items as operation (operation.key)}
                {@const progress = progressValue(operation)}
                <a
                    class="block rounded-lg border p-3 transition hover:bg-muted/40"
                    href={operation.href}
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium">{operation.label}</p>
                                <span
                                    class={`rounded-full px-2 py-0.5 text-xs font-medium ${statusClass(operation.status)}`}
                                >
                                    {statusLabel(operation.status)}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {operation.detail}
                            </p>
                        </div>
                        <p class="shrink-0 text-xs text-muted-foreground">
                            {timeLabel(operation)}
                        </p>
                    </div>

                    {#if progress !== null}
                        <div class="mt-3">
                            <div
                                class="mb-1 flex justify-between text-xs text-muted-foreground"
                            >
                                <span>
                                    {operation.progress_done} done
                                    {#if operation.progress_failed > 0}
                                        · {operation.progress_failed} failed
                                    {/if}
                                    {#if operation.progress_skipped > 0}
                                        · {operation.progress_skipped} skipped
                                    {/if}
                                </span>
                                <span>{progress}%</span>
                            </div>
                            <div
                                class="h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full bg-primary transition-all"
                                    style={`width: ${progress}%`}
                                ></div>
                            </div>
                        </div>
                    {/if}

                    {#if operation.progress_message}
                        <p
                            class="mt-2 line-clamp-2 text-sm text-muted-foreground"
                        >
                            {operation.progress_message}
                        </p>
                    {/if}
                </a>
            {/each}
        </div>
    {/if}
</section>
