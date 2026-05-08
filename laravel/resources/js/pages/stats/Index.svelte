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
        entities,
        workers,
        chat,
        python,
    }: {
        review: Record<string, number>;
        entities: Record<string, number>;
        workers: Record<string, number>;
        chat: Record<string, number>;
        python: {
            available: boolean;
            totals?: Record<string, number>;
            status_counts?: Record<string, number>;
            judge_counts?: Record<string, number>;
            confidence_distribution?: Record<string, number>;
            phase_health?: Record<string, Record<string, number>>;
        };
    } = $props();

    const groups = $derived([
        { title: 'Review', values: review },
        { title: 'Entity approvals', values: entities },
        { title: 'Workers', values: workers },
        { title: 'Chat/RAG', values: chat },
    ]);
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
        <h2 class="mb-3 font-semibold">Python classifier statistics</h2>
        {#if python.available}
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        Legacy/runtime totals
                    </h3>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        {#each Object.entries(python.totals ?? {}) as [label, value] (label)}
                            <div>
                                <dt class="text-sm text-muted-foreground">
                                    {label.replaceAll('_', ' ')}
                                </dt>
                                <dd class="text-xl font-semibold">{value}</dd>
                            </div>
                        {/each}
                    </dl>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        Suggestion status
                    </h3>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        {#each Object.entries(python.status_counts ?? {}) as [label, value] (label)}
                            <div>
                                <dt class="text-sm text-muted-foreground">
                                    {label.replaceAll('_', ' ')}
                                </dt>
                                <dd class="text-xl font-semibold">{value}</dd>
                            </div>
                        {/each}
                    </dl>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        Confidence distribution
                    </h3>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        {#each Object.entries(python.confidence_distribution ?? {}) as [label, value] (label)}
                            <div>
                                <dt class="text-sm text-muted-foreground">
                                    {label}
                                </dt>
                                <dd class="text-xl font-semibold">{value}</dd>
                            </div>
                        {/each}
                    </dl>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        Judge verdicts
                    </h3>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        {#each Object.entries(python.judge_counts ?? {}) as [label, value] (label)}
                            <div>
                                <dt class="text-sm text-muted-foreground">
                                    {label.replaceAll('_', ' ')}
                                </dt>
                                <dd class="text-xl font-semibold">{value}</dd>
                            </div>
                        {/each}
                    </dl>
                </div>
            </div>
            {#if Object.keys(python.phase_health ?? {}).length > 0}
                <div class="mt-4 overflow-x-auto">
                    <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                        Phase health
                    </h3>
                    <table class="w-full text-left text-sm">
                        <thead class="text-muted-foreground">
                            <tr>
                                <th class="py-2">Phase</th>
                                <th class="py-2">Runs</th>
                                <th class="py-2">Errors</th>
                                <th class="py-2">Average ms</th>
                                <th class="py-2">Error rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each Object.entries(python.phase_health ?? {}) as [phase, values] (phase)}
                                <tr class="border-t">
                                    <td class="py-2">{phase}</td>
                                    <td class="py-2">{values.total ?? 0}</td>
                                    <td class="py-2">{values.errors ?? 0}</td>
                                    <td class="py-2">{values.avg_ms ?? 0}</td>
                                    <td class="py-2"
                                        >{values.error_rate_pct ?? 0}%</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        {:else}
            <p class="text-sm text-muted-foreground">
                Python classifier statistics are not available until the
                classifier database exists in the configured data directory.
            </p>
        {/if}
    </section>
</div>
