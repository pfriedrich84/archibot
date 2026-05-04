<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import { Badge, Button, Card, Progressbar } from 'flowbite-svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  let navBadges = $derived({
    review: data.dashboard.kpis.pending_review,
    inbox: data.dashboard.kpis.inbox_pending,
    errors: data.dashboard.kpis.errors_24h,
    tags: data.dashboard.kpis.pending_tags ?? 0,
    correspondents: data.dashboard.kpis.pending_correspondents ?? 0,
    doctypes: data.dashboard.kpis.pending_doctypes ?? 0
  });

  let serviceChecks = $derived([
    { label: 'Setup', ok: data.dashboard.health.setup_complete },
    { label: 'Paperless', ok: data.dashboard.health.paperless_configured },
    { label: 'Ollama', ok: data.dashboard.health.ollama_configured },
    { label: 'Embeddings', ok: data.dashboard.health.embedding_index_ready }
  ]);

  function formatDateTime(value: string | null) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    }).format(date);
  }

  function pollPct() {
    return data.dashboard.pipeline.total > 0 ? Math.round((data.dashboard.pipeline.done / data.dashboard.pipeline.total) * 100) : 0;
  }

  function reindexPct() {
    return data.dashboard.reindex.total > 0 ? Math.round((data.dashboard.reindex.done / data.dashboard.reindex.total) * 100) : 0;
  }

  function phasePct() {
    const total = data.dashboard.pipeline.phase_total ?? 0;
    const done = data.dashboard.pipeline.phase_done ?? 0;
    return total > 0 ? Math.round((done / total) * 100) : 0;
  }

  function phaseLabel(value: string | null) {
    if (!value || value === 'idle') return 'Bereit';
    if (value === 'prepare') return 'Vorbereitung';
    if (value === 'ocr') return 'OCR';
    if (value === 'embed') return 'Embedding';
    if (value === 'classify') return 'Klassifikation';
    if (value === 'judge') return 'Judge';
    if (value === 'store') return 'Speichern';
    if (value === 'postprocess') return 'Nachverarbeitung';
    if (value === 'finalize') return 'Finalisierung';
    return value;
  }

  function readinessTone(ok: boolean) {
    return ok
      ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100'
      : 'border-amber-500/20 bg-amber-500/10 text-amber-100';
  }
</script>

<AppShell
  title="Dashboard"
  subtitle="Kompakte Betriebsübersicht. Für aktive Jobs, Reindex und Live-Protokoll ist die Seite Verarbeitung der operative Mittelpunkt."
  navBadges={navBadges}
>
  {#snippet children()}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Review offen" value={data.dashboard.kpis.pending_review} hint="Vorschläge warten auf Freigabe" accent="emerald" />
      <StatCard title="Fehler 24h" value={data.dashboard.kpis.errors_24h} hint="Aktive Warnsignale" accent="red" />
      <StatCard title="Inbox offen" value={data.dashboard.kpis.inbox_pending} hint="Dokumente im Posteingang" accent="blue" />
      <StatCard title="Heute bestätigt" value={data.dashboard.kpis.committed_today} hint="Übernommene Dokumente" accent="purple" />
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(19rem,0.8fr)]">
      <div class="grid gap-4 xl:grid-cols-2">
        <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pipeline</p>
              <h2 class="mt-2 text-lg font-semibold text-white">Dokumentverarbeitung</h2>
              <p class="mt-1.5 text-sm text-slate-400">Status des aktuellen Polling-Laufs. Start, Stopp und Job-Protokoll liegen unter Verarbeitung.</p>
            </div>
            <Badge color={data.dashboard.pipeline.running ? 'blue' : 'green'}>{data.dashboard.pipeline.running ? phaseLabel(data.dashboard.pipeline.phase) : 'Bereit'}</Badge>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
            <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
              <span>Gesamtfortschritt</span>
              <span>{data.dashboard.pipeline.total > 0 ? `${data.dashboard.pipeline.done}/${data.dashboard.pipeline.total}` : 'Kein aktiver Lauf'}</span>
            </div>
            <Progressbar progress={pollPct()} color="blue" />
            <div class="mt-3 grid gap-3 text-sm text-slate-400 sm:grid-cols-2">
              <div>Erfolgreich: <span class="text-slate-200">{data.dashboard.pipeline.succeeded}</span></div>
              <div>Fehler: <span class="text-slate-200">{data.dashboard.pipeline.failed}</span></div>
            </div>
            {#if (data.dashboard.pipeline.phase_total ?? 0) > 0}
              <div class="mt-3 border-t border-slate-800 pt-3">
                <div class="mb-2 flex items-center justify-between text-xs text-slate-400">
                  <span>Aktuelle Phase: {phaseLabel(data.dashboard.pipeline.phase)}</span>
                  <span>{data.dashboard.pipeline.phase_done ?? 0}/{data.dashboard.pipeline.phase_total ?? 0}</span>
                </div>
                <Progressbar progress={phasePct()} color="green" />
              </div>
            {/if}
          </div>

          <div class="mt-5 flex flex-wrap gap-3">
            <a href="/app/processing" class="inline-flex"><Button color="blue">Verarbeitung öffnen</Button></a>
            <a href="/app/review" class="inline-flex"><Button color="dark" class="rounded-xl border border-slate-700">Review Queue</Button></a>
          </div>
        </Card>

        <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embeddings</p>
              <h2 class="mt-2 text-lg font-semibold text-white">Reindex</h2>
              <p class="mt-1.5 text-sm text-slate-400">Indexzustand für semantische Suche und Kontext. Rebuild-Aktionen sind in Verarbeitung gebündelt.</p>
            </div>
            <Badge color={data.dashboard.reindex.running ? 'purple' : data.dashboard.health.embedding_index_ready ? 'green' : 'yellow'}>
              {data.dashboard.reindex.running ? 'Aktiv' : data.dashboard.health.embedding_index_ready ? 'Bereit' : 'Fehlt'}
            </Badge>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
            <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
              <span>Reindex-Fortschritt</span>
              <span>{data.dashboard.reindex.total > 0 ? `${data.dashboard.reindex.done}/${data.dashboard.reindex.total}` : 'Kein aktiver Lauf'}</span>
            </div>
            <Progressbar progress={reindexPct()} color="purple" />
            <div class="mt-3 grid gap-3 text-sm text-slate-400 sm:grid-cols-2">
              <div>Fehler: <span class="text-slate-200">{data.dashboard.reindex.failed}</span></div>
              <div>OCR: <span class="text-slate-200">{data.dashboard.health.ocr_mode}</span></div>
            </div>
          </div>

          <div class="mt-5 flex flex-wrap gap-3">
            <a href="/app/processing" class="inline-flex"><Button color="purple">Reindex verwalten</Button></a>
            <a href="/app/embeddings" class="inline-flex"><Button color="dark" class="rounded-xl border border-slate-700">Embeddings ansehen</Button></a>
          </div>
        </Card>
      </div>

      <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20 xl:sticky xl:top-24 xl:self-start">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Betriebsstatus</p>
            <h2 class="mt-2 text-lg font-semibold text-white">Systembereitschaft</h2>
          </div>
          <Badge color={data.status.app.setup_complete ? 'green' : 'yellow'}>{data.status.app.setup_complete ? 'Bereit' : 'Setup offen'}</Badge>
        </div>

        <div class="mt-5 space-y-2.5 text-sm">
          {#each serviceChecks as check}
            <div class={`rounded-2xl border p-3.5 ${readinessTone(check.ok)}`}>
              <div class="text-xs uppercase tracking-[0.2em] opacity-70">{check.label}</div>
              <div class="mt-2 font-medium">{check.ok ? 'OK' : 'Prüfen'}</div>
            </div>
          {/each}
          <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5 text-slate-300">
            <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Nächster Poll</div>
            <div class="mt-2 font-medium text-white">{formatDateTime(data.dashboard.pipeline.next_run_at)}</div>
          </div>
          <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5 text-slate-300">
            <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Auto-Commit</div>
            <div class="mt-2 font-medium text-white">{data.dashboard.health.auto_commit_confidence}%</div>
          </div>
        </div>
      </Card>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[0.95fr,1.05fr]">
      <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Offene Arbeit</p>
            <h2 class="mt-2 text-lg font-semibold text-white">Nächste Entscheidungen</h2>
            <p class="mt-1.5 text-sm text-slate-400">Direkte Einstiege in die wichtigsten Warteschlangen.</p>
          </div>
          <Badge color="gray">Triage</Badge>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
          <a href="/app/review" class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-3.5 text-emerald-100 transition hover:border-emerald-400/40 hover:bg-emerald-500/15">
            <div class="text-xs uppercase tracking-[0.2em] opacity-70">Review</div>
            <div class="mt-2 text-2xl font-semibold">{data.dashboard.kpis.pending_review}</div>
            <div class="mt-1 text-sm opacity-80">Vorschläge freigeben</div>
          </a>
          <a href="/app/inbox" class="rounded-2xl border border-blue-500/20 bg-blue-500/10 p-3.5 text-blue-100 transition hover:border-blue-400/40 hover:bg-blue-500/15">
            <div class="text-xs uppercase tracking-[0.2em] opacity-70">Posteingang</div>
            <div class="mt-2 text-2xl font-semibold">{data.dashboard.kpis.inbox_pending}</div>
            <div class="mt-1 text-sm opacity-80">Inbox-Dokumente prüfen</div>
          </a>
          <a href="/app/tags" class="rounded-2xl border border-purple-500/20 bg-purple-500/10 p-3.5 text-purple-100 transition hover:border-purple-400/40 hover:bg-purple-500/15">
            <div class="text-xs uppercase tracking-[0.2em] opacity-70">Entitäten</div>
            <div class="mt-2 text-2xl font-semibold">{(data.dashboard.kpis.pending_tags ?? 0) + (data.dashboard.kpis.pending_correspondents ?? 0) + (data.dashboard.kpis.pending_doctypes ?? 0)}</div>
            <div class="mt-1 text-sm opacity-80">Tags, Korrespondenten, Typen</div>
          </a>
          <a href="/app/errors" class="rounded-2xl border border-rose-500/20 bg-rose-500/10 p-3.5 text-rose-100 transition hover:border-rose-400/40 hover:bg-rose-500/15">
            <div class="text-xs uppercase tracking-[0.2em] opacity-70">Fehler</div>
            <div class="mt-2 text-2xl font-semibold">{data.dashboard.kpis.errors_24h}</div>
            <div class="mt-1 text-sm opacity-80">Probleme triagieren</div>
          </a>
        </div>
      </Card>

      <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Letzte Fehler</p>
            <h2 class="mt-2 text-lg font-semibold text-white">Schnelle Triage</h2>
          </div>
          <Badge color={data.dashboard.recent_errors.length > 0 ? 'red' : 'green'}>
            {data.dashboard.recent_errors.length > 0 ? `${data.dashboard.recent_errors.length} aktiv` : 'Keine Signale'}
          </Badge>
        </div>

        <div class="mt-5 space-y-3">
          {#each data.dashboard.recent_errors.slice(0, 3) as item}
            <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
              <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                  <Badge color="red">{item.stage}</Badge>
                  {#if item.document_id}
                    <Badge color="gray">Dokument #{item.document_id}</Badge>
                  {/if}
                </div>
                <span class="text-xs text-slate-500">{formatDateTime(item.occurred_at)}</span>
              </div>
              <p class="mt-2.5 font-medium text-white">{item.message}</p>
              <p class="mt-1.5 text-sm text-slate-400">{item.details || 'Keine Zusatzdetails vorhanden.'}</p>
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
