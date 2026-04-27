<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import { Badge, Card } from 'flowbite-svelte';
  import StatusPanel from '$lib/components/StatusPanel.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  let serviceChecks = $derived([
    { label: 'Setup', ok: data.dashboard.health.setup_complete },
    { label: 'Paperless', ok: data.dashboard.health.paperless_configured },
    { label: 'Ollama', ok: data.dashboard.health.ollama_configured },
    { label: 'Embeddings', ok: data.dashboard.health.embedding_index_ready }
  ]);
</script>

<AppShell
  title="Dashboard"
  subtitle="Neue Admin-Oberfläche auf SvelteKit-Basis. Die Kernmetriken kommen bereits aus /api/v1/dashboard und /api/v1/system/status."
>
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Pending Review" value={data.dashboard.kpis.pending_review} hint="Vorschläge warten auf Freigabe" accent="emerald" />
      <StatCard title="Errors (24h)" value={data.dashboard.kpis.errors_24h} hint="Fehler der letzten 24 Stunden" accent="red" />
      <StatCard title="Inbox Pending" value={data.dashboard.kpis.inbox_pending} hint="Dokumente im Posteingang" accent="blue" />
      <StatCard
        title="Committed Today"
        value={data.dashboard.kpis.committed_today}
        hint="Heute bestätigte Dokumente"
        accent="purple"
      />
    </div>

    <div class="mt-6">
      <StatusPanel dashboard={data.dashboard} status={data.status} />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.1fr,0.9fr]">
      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">System Readiness</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">At a glance</h2>
          </div>
          <Badge color={data.status.app.legacy_ui.cutover_ready ? 'green' : 'yellow'}>
            {data.status.app.legacy_ui.cutover_ready ? 'Cutover ready' : 'Migration mode'}
          </Badge>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {#each serviceChecks as check}
            <div class={`rounded-2xl border p-4 ${check.ok ? 'border-emerald-500/20 bg-emerald-500/10' : 'border-amber-500/20 bg-amber-500/10'}`}>
              <p class="text-xs uppercase tracking-wide text-slate-400">{check.label}</p>
              <p class={`mt-2 text-lg font-semibold ${check.ok ? 'text-emerald-100' : 'text-amber-100'}`}>
                {check.ok ? 'OK' : 'Needs attention'}
              </p>
            </div>
          {/each}
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Poll interval</p>
            <p class="mt-2 text-lg font-semibold text-white">{data.dashboard.health.poll_interval_seconds}s</p>
          </div>
          <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Frontend mode</p>
            <p class="mt-2 text-lg font-semibold text-white">{data.status.app.frontend.mode}</p>
          </div>
        </div>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Recent Errors</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Fast triage</h2>
          </div>
          <Badge color={data.dashboard.recent_errors.length > 0 ? 'red' : 'green'}>
            {data.dashboard.recent_errors.length > 0 ? `${data.dashboard.recent_errors.length} open signals` : 'No active signals'}
          </Badge>
        </div>

        <div class="mt-6 space-y-3">
          {#each data.dashboard.recent_errors.slice(0, 3) as item}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
              <div class="flex items-center justify-between gap-3">
                <Badge color="red">{item.stage}</Badge>
                <span class="text-xs text-slate-500">{item.occurred_at}</span>
              </div>
              <p class="mt-3 font-medium text-white">{item.message}</p>
              <p class="mt-2 text-sm text-slate-400">{item.details || 'Keine Zusatzdetails vorhanden.'}</p>
            </div>
          {:else}
            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4 text-sm text-emerald-100">
              Keine aktuellen Fehler in den letzten API-Daten.
            </div>
          {/each}
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
