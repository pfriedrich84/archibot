<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { index as reviewIndex } from '@/routes/review';
    import { index as workerJobsIndex } from '@/routes/worker-jobs';

    type DashboardStatus = {
        setup_complete: boolean;
        paperless_url_configured: boolean;
        paperless_available: boolean | null;
        paperless_error: string | null;
        inbox_tag_id: number;
    };

    type Counts = {
        pending_reviews: number;
        queued_or_running_workers: number;
        failed_workers: number;
    };

    type RecentWorkerJob = {
        id: number;
        type: string;
        status: string;
        created_at: string | null;
        finished_at: string | null;
    };

    let {
        status,
        counts,
        recentWorkerJobs,
    }: {
        status: DashboardStatus;
        counts: Counts;
        recentWorkerJobs: RecentWorkerJob[];
    } = $props();

    const paperlessLabel = $derived(
        status.paperless_available === null
            ? 'Not checked'
            : status.paperless_available
              ? 'Available'
              : 'Unavailable',
    );
</script>

<AppHead title="Dashboard" />

<div class="space-y-6">
    <Heading title="Dashboard" description="ArchiBot application status." />

    <div class="grid gap-4 md:grid-cols-3">
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={reviewIndex().url}
        >
            <div class="text-sm text-muted-foreground">Pending reviews</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.pending_reviews}
            </div>
        </a>
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={workerJobsIndex().url}
        >
            <div class="text-sm text-muted-foreground">
                Queued/running workers
            </div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.queued_or_running_workers}
            </div>
        </a>
        <a
            class="rounded-xl border p-4 hover:bg-muted/50"
            href={workerJobsIndex().url}
        >
            <div class="text-sm text-muted-foreground">Failed workers</div>
            <div class="mt-2 text-3xl font-semibold">
                {counts.failed_workers}
            </div>
        </a>
    </div>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">System status</h2>
        <dl class="grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Setup complete</dt>
                <dd>{status.setup_complete ? 'Yes' : 'No'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Paperless URL configured</dt>
                <dd>{status.paperless_url_configured ? 'Yes' : 'No'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Paperless availability</dt>
                <dd>{paperlessLabel}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Inbox tag ID</dt>
                <dd>{status.inbox_tag_id || 'Not configured'}</dd>
            </div>
        </dl>
        {#if status.paperless_error}
            <p class="mt-3 text-sm text-destructive">
                {status.paperless_error}
            </p>
        {/if}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-semibold">Recent worker jobs</div>
        {#each recentWorkerJobs as job (job.id)}
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium">#{job.id} {job.type}</span>
                <span class="rounded-full bg-muted px-2 py-0.5"
                    >{job.status}</span
                >
                {#if job.finished_at}
                    <span class="text-muted-foreground"
                        >finished {job.finished_at}</span
                    >
                {:else if job.created_at}
                    <span class="text-muted-foreground"
                        >created {job.created_at}</span
                    >
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No worker jobs yet.
            </div>
        {/each}
    </section>
</div>
