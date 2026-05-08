<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Errors', href: '/errors' }],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { displayEntries } from '@/lib/display';

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
        progress: Record<string, unknown>;
        created_at: string | null;
        finished_at: string | null;
    };

    let {
        failedJobs,
        legacyErrors,
    }: {
        failedJobs: FailedJob[];
        legacyErrors: LegacyError[];
    } = $props();
</script>

<AppHead title="Errors" />

<div class="space-y-6">
    <Heading
        title="Errors"
        description="Recent failed, partially failed, and cancelled worker jobs restored from the old diagnostics workflow."
    />

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 text-sm text-muted-foreground">
            {failedJobs.length} recent worker diagnostic event{failedJobs.length ===
            1
                ? ''
                : 's'}
        </div>

        {#each failedJobs as job (job.id)}
            <article class="space-y-2 border-b p-4 text-sm last:border-b-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium"
                        >Worker job {job.id} · {job.type}</span
                    >
                    <span class="rounded-full bg-muted px-2 py-0.5"
                        >{job.status}</span
                    >
                    {#if job.finished_at}
                        <span class="text-muted-foreground"
                            >finished {job.finished_at}</span
                        >
                    {/if}
                </div>
                {#if job.error}
                    <p class="text-destructive">{job.error}</p>
                {/if}
                {#if displayEntries(job.progress).length > 0}
                    <dl class="grid gap-1 text-xs">
                        {#each displayEntries(job.progress) as entry (entry.key)}
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
                No recent worker errors.
            </div>
        {/each}
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
                            {error.occurred_at}
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
                No recent Python classifier errors.
            </div>
        {/each}
    </section>
</div>
