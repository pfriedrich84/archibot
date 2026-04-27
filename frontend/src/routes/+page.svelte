<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import { Badge, Card } from 'flowbite-svelte';
  import StatusPanel from '$lib/components/StatusPanel.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  function formatDateTime(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    }).format(date);
  }

  let serviceChecks = $derived([
    { label: 'Setup', ok: data.dashboard.health.setup_complete },
    { label: 'Paperless', ok: data.dashboard.health.paperless_configured },
    { label: 'Ollama', ok: data.dashboard.health.ollama_configured },
    { label: 'Embeddings', ok: data.dashboard.health.embedding_index_ready }
  ]);
</script>

<AppShell
  title="Dashboard"
  subtitle="Gesundheit, Durchsatz und offene Risiken auf einen Blick — mit direktem Fokus auf tägliche Betriebsentscheidungen."
>
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Review offen" value={data.dashboard.kpis.pending_review} hint="Vorschläge warten auf Freigabe" accent="emerald" />
      <StatCard title="Fehler 24h" value={data.dashboard.kpis.errors_24h} hint="Aktive Warnsignale" accent="red" />
      <StatCard title="Inbox offen" value={data.dashboard.kpis.inbox_pending} hint="Dokumente im Posteingang" accent="blue" />
      <StatCard
        title="Heute bestätigt"
        value={data.dashboard.kpis.committed_today}
        hint="Bereits übernommene Dokumente"
        accent="purple"
      />
    </div>

    <div class="mt-6">
      <StatusPanel dashboard={data.dashboard} status={data.status} />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.95fr,1.05fr]">
      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Systembereitschaft</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Auf einen Blick</h2>
          </div>
          <Badge color={data.status.app.legacy_ui.cutover_ready ? 'green' : 'yellow'}>
            {data.status.app.legacy_ui.cutover_ready ? 'Bereit' : 'Migration'}
          </Badge>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2">
          {#each serviceChecks as check}
            <div class={`rounded-2xl border p-4 ${check.ok ? 'border-emerald-500/20 bg-emerald-500/10' : 'border-amber-500/20 bg-amber-500/10'}`}>
              <p class="text-xs uppercase tracking-wide text-slate-400">{check.label}</p>
              <p class={`mt-2 text-lg font-semibold ${check.ok ? 'text-emerald-100' : 'text-amber-100'}`}>
                {check.ok ? 'OK' : 'Prüfen'}
              </p>
            </div>
          {/each}
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Poll-Intervall</p>
            <p class="mt-2 text-lg font-semibold text-white">{data.dashboard.health.poll_interval_seconds}s</p>
          </div>
          <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Frontend-Modus</p>
            <p class="mt-2 text-lg font-semibold text-white capitalize">{data.status.app.frontend.mode}</p>
          </div>
        </div>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Letzte Fehler</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Schnelle Triage</h2>
          </div>
          <Badge color={data.dashboard.recent_errors.length > 0 ? 'red' : 'green'}>
            {data.dashboard.recent_errors.length > 0 ? `${data.dashboard.recent_errors.length} aktiv` : 'Keine Signale'}
          </Badge>
        </div>

        <div class="mt-6 space-y-3">
          {#each data.dashboard.recent_errors.slice(0, 3) as item}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
              <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                  <Badge color="red">{item.stage}</Badge>
                  {#if item.document_id}
                    <Badge color="gray">Dokument #{item.document_id}</Badge>
                  {/if}
                </div>
                <span class="text-xs text-slate-500">{formatDateTime(item.occurred_at)}</span>
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
