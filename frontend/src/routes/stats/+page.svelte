<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  let statusEntries = $derived(Object.entries(data.stats.status_counts));
  let phaseEntries = $derived(
    Object.entries(data.stats.phase_health) as Array<
      [string, { total: number; errors: number; avg_ms: number; error_rate_pct: number }]
    >
  );
  let confidenceEntries = $derived(Object.entries(data.stats.confidence_distribution));
</script>

<AppShell title="Statistiken" subtitle="Metriken aus Audit-, Fehler- und Phasen-Tabellen werden im neuen Dashboard-Stil zusammengeführt.">
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Processed" value={data.stats.totals.processed_documents} hint="Dokumente mit Status" accent="blue" />
      <StatCard title="Embedded" value={data.stats.totals.embedded_documents} hint="Im Embedding-Index" accent="emerald" />
      <StatCard title="Commits" value={data.stats.totals.total_commits} hint={`Auto: ${data.stats.totals.auto_commits}`} accent="purple" />
      <StatCard title="Errors" value={data.stats.totals.total_errors} hint="Alle Fehlerereignisse" accent="red" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Suggestion Status</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">Verteilung</h2>
        <div class="mt-6 flex flex-wrap gap-2">
          {#each statusEntries as [status, count]}
            <Badge color="gray">{status}: {count}</Badge>
          {/each}
        </div>

        <div class="mt-6 space-y-3">
          {#each confidenceEntries as [bucket, count]}
            <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 text-sm text-slate-300">
              <span>Confidence {bucket}</span>
              <span class="font-semibold text-white">{count}</span>
            </div>
          {/each}
        </div>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Phase Health</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">Letzte 30 Tage</h2>
        <div class="mt-6 space-y-3">
          {#each phaseEntries as [phase, stats]}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
              <div class="flex items-center justify-between gap-3">
                <div class="font-medium text-white">{phase}</div>
                <Badge color={stats.error_rate_pct > 0 ? 'yellow' : 'green'}>{stats.error_rate_pct}% Fehler</Badge>
              </div>
              <div class="mt-2 grid gap-2 text-sm text-slate-400 md:grid-cols-3">
                <div>Total: {stats.total}</div>
                <div>Errors: {stats.errors}</div>
                <div>Avg: {stats.avg_ms} ms</div>
              </div>
            </div>
          {:else}
            <p class="text-sm text-slate-400">Noch keine Phasenmetriken vorhanden.</p>
          {/each}
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
