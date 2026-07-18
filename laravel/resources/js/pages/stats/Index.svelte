<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Stats', href: '/stats' }],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';

    let {
        review,
        reviewStatusCounts,
        reviewConfidenceDistribution,
        reviewJudgeCounts,
        entities,
        entityApprovalMatrix,
        webhookStatusCounts,
        pipelineRunStatusCounts,
        pipelineRunTypeMatrix,
        actorStatusCounts,
        actorNameMatrix,
        dailyActivity,
    }: {
        review: Record<string, number>;
        reviewStatusCounts: Record<string, number>;
        reviewConfidenceDistribution: Record<string, number>;
        reviewJudgeCounts: Record<string, number>;
        entities: Record<string, number>;
        entityApprovalMatrix: Record<string, Record<string, number>>;
        webhookStatusCounts: Record<string, number>;
        pipelineRunStatusCounts: Record<string, number>;
        pipelineRunTypeMatrix: Record<string, Record<string, number>>;
        actorStatusCounts: Record<string, number>;
        actorNameMatrix: Record<string, Record<string, number>>;
        dailyActivity: Array<Record<string, number | string>>;
    } = $props();

    const groups = $derived([
        { title: 'Review', values: review },
        { title: 'Entity approvals', values: entities },
    ]);

    const distributionGroups = $derived([
        { title: 'Review status', values: reviewStatusCounts },
        { title: 'Review confidence', values: reviewConfidenceDistribution },
        { title: 'Judge verdicts', values: reviewJudgeCounts },
        { title: 'Webhook delivery status', values: webhookStatusCounts },
        { title: 'Pipeline run status', values: pipelineRunStatusCounts },
        { title: 'Actor execution status', values: actorStatusCounts },
    ]);

    const matrixGroups = $derived([
        { title: 'Entity approvals by type', values: entityApprovalMatrix },
        { title: 'Pipeline runs by type', values: pipelineRunTypeMatrix },
        { title: 'Actor executions by actor', values: actorNameMatrix },
    ]);

    const matrixStatuses = (values: Record<string, Record<string, number>>) => [
        ...new Set(Object.values(values).flatMap((row) => Object.keys(row))),
    ];
</script>

<AppHead title="Stats" />

<div class="space-y-6">
    <Heading
        title="Stats"
        description="Operational counters restored from the old statistics workflow."
    />

    <div class="grid gap-4 md:grid-cols-2">
        {#each groups as group (group.title)}
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 font-semibold">{group.title}</h2>
                <dl class="grid gap-3 sm:grid-cols-2">
                    {#each Object.entries(group.values) as [label, value] (label)}
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                {label.replaceAll('_', ' ')}
                            </dt>
                            <dd class="text-2xl font-semibold">{value}</dd>
                        </div>
                    {/each}
                </dl>
            </section>
        {/each}
    </div>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Laravel-native distributions</h2>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {#each distributionGroups as group (group.title)}
                <div>
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        {group.title}
                    </h3>
                    {#if Object.keys(group.values).length > 0}
                        <dl class="grid gap-2 sm:grid-cols-2">
                            {#each Object.entries(group.values) as [label, value] (label)}
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        {label.replaceAll('_', ' ')}
                                    </dt>
                                    <dd class="text-xl font-semibold">
                                        {value}
                                    </dd>
                                </div>
                            {/each}
                        </dl>
                    {:else}
                        <p class="text-sm text-muted-foreground">
                            No data yet.
                        </p>
                    {/if}
                </div>
            {/each}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Operational breakdowns</h2>
        <div class="grid gap-4 xl:grid-cols-2">
            {#each matrixGroups as group (group.title)}
                <div class="overflow-x-auto">
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        {group.title}
                    </h3>
                    {#if Object.keys(group.values).length > 0}
                        <table class="w-full text-left text-sm">
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th class="py-2">Type</th>
                                    {#each matrixStatuses(group.values) as status (status)}
                                        <th class="py-2"
                                            >{status.replaceAll('_', ' ')}</th
                                        >
                                    {/each}
                                </tr>
                            </thead>
                            <tbody>
                                {#each Object.entries(group.values) as [rowLabel, rowValues] (rowLabel)}
                                    <tr class="border-t">
                                        <td class="py-2"
                                            >{rowLabel.replaceAll('_', ' ')}</td
                                        >
                                        {#each matrixStatuses(group.values) as status (status)}
                                            <td class="py-2"
                                                >{rowValues[status] ?? 0}</td
                                            >
                                        {/each}
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    {:else}
                        <p class="text-sm text-muted-foreground">
                            No data yet.
                        </p>
                    {/if}
                </div>
            {/each}
        </div>
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="mb-3 font-semibold">Recent daily activity</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-muted-foreground">
                    <tr>
                        <th class="py-2">Date</th>
                        <th class="py-2">Reviews created</th>
                        <th class="py-2">Reviews completed</th>
                        <th class="py-2">Commands finished</th>
                        <th class="py-2">Webhook deliveries</th>
                        <th class="py-2">Pipeline runs</th>
                    </tr>
                </thead>
                <tbody>
                    {#each dailyActivity as day (day.date)}
                        <tr class="border-t">
                            <td class="py-2">{day.date}</td>
                            <td class="py-2">{day.reviews_created}</td>
                            <td class="py-2">{day.reviews_completed}</td>
                            <td class="py-2">{day.commands_finished}</td>
                            <td class="py-2">{day.webhook_deliveries}</td>
                            <td class="py-2">{day.pipeline_runs}</td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    </section>
</div>
