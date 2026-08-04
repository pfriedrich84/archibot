<script lang="ts">
    import Ban from 'lucide-svelte/icons/ban';
    import CircleCheck from 'lucide-svelte/icons/circle-check';
    import CircleDashed from 'lucide-svelte/icons/circle-dashed';
    import CircleX from 'lucide-svelte/icons/circle-x';
    import Clock3 from 'lucide-svelte/icons/clock-3';
    import PauseCircle from 'lucide-svelte/icons/pause-circle';
    import RotateCw from 'lucide-svelte/icons/rotate-cw';
    import TriangleAlert from 'lucide-svelte/icons/triangle-alert';
    import { cn } from '@/lib/utils';

    let {
        status,
        label = '',
        class: className = '',
    }: {
        status: string | null | undefined;
        label?: string;
        class?: string;
    } = $props();

    const normalized = $derived((status ?? 'unknown').trim().toLowerCase());

    const presentation = $derived.by(() => {
        if (
            [
                'completed',
                'complete',
                'accepted',
                'approved',
                'ready',
                'ok',
                'processed',
                'succeeded',
                'committed',
                'synced',
                'in_sync',
                'written_back',
            ].includes(normalized)
        ) {
            return {
                icon: CircleCheck,
                class: 'border-emerald-700/20 bg-emerald-700/10 text-emerald-800 dark:text-emerald-300',
            };
        }

        if (
            [
                'failed',
                'failed_permanent',
                'error',
                'rejected',
                'unavailable',
                'missing',
                'remote_read_failed',
                'pipeline_start_failed',
                'write_back_failed',
            ].includes(normalized)
        ) {
            return {
                icon: CircleX,
                class: 'border-destructive/25 bg-destructive/10 text-destructive',
            };
        }

        if (
            [
                'blocked',
                'stale',
                'warning',
                'problem',
                'partial',
                'partially_failed',
                'degraded',
                'drift_detected',
                'cancel_requested',
            ].includes(normalized)
        ) {
            return {
                icon: TriangleAlert,
                class: 'border-amber-700/25 bg-amber-600/10 text-amber-800 dark:text-amber-300',
            };
        }

        if (
            ['running', 'processing', 'building', 'active'].includes(normalized)
        ) {
            return {
                icon: RotateCw,
                class: 'border-sky-700/20 bg-sky-700/10 text-sky-800 dark:text-sky-300',
            };
        }

        if (
            ['queued', 'pending', 'received', 'new', 'requested'].includes(
                normalized,
            )
        ) {
            return {
                icon: Clock3,
                class: 'border-primary/20 bg-primary/10 text-primary',
            };
        }

        if (['retrying', 'recovering'].includes(normalized)) {
            return {
                icon: RotateCw,
                class: 'border-violet-700/20 bg-violet-700/10 text-violet-800 dark:text-violet-300',
            };
        }

        if (
            [
                'cancelled',
                'canceled',
                'dismissed',
                'skipped',
                'duplicate',
                'suppressed',
            ].includes(normalized)
        ) {
            return {
                icon: Ban,
                class: 'border-border bg-muted text-muted-foreground',
            };
        }

        if (['paused', 'waiting'].includes(normalized)) {
            return {
                icon: PauseCircle,
                class: 'border-border bg-muted text-muted-foreground',
            };
        }

        return {
            icon: CircleDashed,
            class: 'border-border bg-muted text-muted-foreground',
        };
    });

    const visibleLabel = $derived(
        label || normalized.replaceAll('_', ' ').replaceAll('-', ' '),
    );
</script>

<span
    class={cn(
        'inline-flex min-h-7 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium capitalize',
        presentation.class,
        className,
    )}
>
    <presentation.icon class="size-3.5 shrink-0" aria-hidden="true" />
    <span>{visibleLabel}</span>
</span>
