<script lang="ts">
  import { Badge, Button, Card, Progressbar } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import {
    cancelPoll,
    cancelReindexJob,
    loadChat,
    loadDashboard,
    loadErrors,
    loadJobEvents,
    loadStatus,
    startPoll,
    startPollAll,
    startReindex
  } from '$lib/api';
  import type { ChatPayload, DashboardPayload, ErrorsPayload, JobEvent, StatusPayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  const initialData = () => data;
  let dashboard = $state<DashboardPayload>(initialData().dashboard);
  let status = $state<StatusPayload>(initialData().status);
  let errors = $state<ErrorsPayload>(initialData().errors);
  let chat = $state<ChatPayload>(initialData().chat);
  let actionState = $state<'poll-start' | 'poll-all-start' | 'poll-cancel' | 'reindex-start' | 'reindex-cancel' | ''>('');
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let refreshing = $state(false);
  let jobEvents = $state<JobEvent[]>([]);
  let latestJobEventId = $state(0);

  let logEntries = $derived.by(() => {
    const errorRows = errors.items.map((item) => ({
      kind: 'Fehler',
      tone: 'rose',
      occurred_at: item.occurred_at,
      title: `${item.stage}${item.document_id ? ` · Dokument #${item.document_id}` : ''}`,
      details: item.details || item.message
    }));
    const activityRows = chat.recent_activity.map((item) => ({
      kind: 'Audit',
      tone: 'slate',
      occurred_at: item.occurred_at,
      title: 'Aktivität',
      details: item.details || 'Aktivität ohne Details'
    }));
    return [...errorRows, ...activityRows]
      .sort((a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime())
      .slice(0, 20);
  });

  function pollPct() {
    return dashboard.pipeline.total > 0 ? Math.round((dashboard.pipeline.done / dashboard.pipeline.total) * 100) : 0;
  }

  function reindexPct() {
    return dashboard.reindex.total > 0 ? Math.round((dashboard.reindex.done / dashboard.reindex.total) * 100) : 0;
  }

  function phasePct() {
    const total = dashboard.pipeline.phase_total ?? 0;
    const done = dashboard.pipeline.phase_done ?? 0;
    return total > 0 ? Math.round((done / total) * 100) : 0;
  }

  function readinessTone(ok: boolean) {
    return ok
      ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100'
      : 'border-amber-500/20 bg-amber-500/10 text-amber-100';
  }

  function formatDateTime(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    }).format(date);
  }

  async function refreshProcessing() {
    refreshing = true;
    try {
      const [nextDashboard, nextStatus, nextErrors, nextChat] = await Promise.all([
        loadDashboard(fetch),
        loadStatus(fetch),
        loadErrors(fetch),
        loadChat(fetch)
      ]);
      dashboard = nextDashboard;
      status = nextStatus;
      errors = nextErrors;
      chat = nextChat;

      const jobId = nextDashboard.reindex.running
        ? nextDashboard.reindex.job_id
        : nextDashboard.pipeline.job_id ?? nextDashboard.reindex.job_id;
      if (jobId) {
        if (jobEvents[0]?.job_id && jobEvents[0].job_id !== jobId) {
          jobEvents = [];
          latestJobEventId = 0;
        }
        const payload = await loadJobEvents(jobId, fetch, latestJobEventId);
        if (payload.events.length > 0) {
          jobEvents = [...jobEvents, ...payload.events].slice(-250);
          latestJobEventId = payload.latest_id;
        }
      }
    } finally {
      refreshing = false;
    }
  }

  async function runJobAction(
    key: 'poll-start' | 'poll-all-start' | 'poll-cancel' | 'reindex-start' | 'reindex-cancel',
    action: () => Promise<DashboardPayload['pipeline'] | DashboardPayload['reindex']>
  ) {
    actionState = key;
    feedback = null;
    try {
      const result = await action();
      if (key.startsWith('poll')) {
        dashboard = { ...dashboard, pipeline: result as DashboardPayload['pipeline'] };
        jobEvents = [];
        latestJobEventId = 0;
      } else {
        dashboard = { ...dashboard, reindex: result as DashboardPayload['reindex'] };
        jobEvents = [];
        latestJobEventId = 0;
      }
      feedback = {
        type: 'success',
        message:
          key === 'poll-start'
            ? 'Polling gestartet.'
            : key === 'poll-all-start'
              ? 'Prüfung aller Dokumente gestartet.'
              : key === 'poll-cancel'
              ? 'Polling-Abbruch angefordert.'
              : key === 'reindex-start'
                ? 'Reindex gestartet.'
                : 'Reindex-Abbruch angefordert.'
      };
      await refreshProcessing();
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Aktion fehlgeschlagen.' };
    } finally {
      actionState = '';
    }
  }

  $effect(() => {
    if (typeof window === 'undefined') return;
    const timer = window.setInterval(() => void refreshProcessing(), 5000);
    return () => window.clearInterval(timer);
  });
</script>

<AppShell title="Verarbeitung" subtitle="Polling, Reindex, Laufzeitstatus und Live-Log getrennt von der Konfiguration überwachen und steuern." navBadges={{ errors: errors.items.length }}>
  {#snippet children()}
    {#if feedback}
      <div class={`mb-6 rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
        {feedback.message}
      </div>
    {/if}

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(19rem,0.8fr)]">
      <div class="grid gap-4 xl:grid-cols-2">
        <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Polling</p>
              <h2 class="mt-2 text-lg font-semibold text-white">Dokumente verarbeiten</h2>
              <p class="mt-1.5 text-sm text-slate-400">Startet den nächsten Erfassungs- und Klassifizierungslauf für neue Dokumente oder prüft optional alle Paperless-Dokumente.</p>
            </div>
            <Badge color={dashboard.pipeline.running ? 'blue' : 'green'}>{dashboard.pipeline.running ? 'Aktiv' : 'Bereit'}</Badge>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
            <div class="mb-2 flex items-center justify-between text-sm text-slate-300"><span>Fortschritt</span><span>{dashboard.pipeline.total > 0 ? `${dashboard.pipeline.done}/${dashboard.pipeline.total}` : 'Kein aktiver Lauf'}</span></div>
            <Progressbar progress={pollPct()} color="blue" />
            <div class="mt-3 grid gap-3 text-sm text-slate-400 sm:grid-cols-2"><div>Phase: <span class="text-slate-200">{dashboard.pipeline.phase || 'prepare'}</span></div><div>Fehler: <span class="text-slate-200">{dashboard.pipeline.failed}</span></div></div>
            {#if (dashboard.pipeline.phase_total ?? 0) > 0}
              <div class="mt-3 border-t border-slate-800 pt-3">
                <div class="mb-2 flex items-center justify-between text-xs text-slate-400"><span>Phasenfortschritt</span><span>{dashboard.pipeline.phase_done ?? 0}/{dashboard.pipeline.phase_total ?? 0}</span></div>
                <Progressbar progress={phasePct()} color="green" />
              </div>
            {/if}
          </div>

          <div class="mt-5 flex flex-wrap gap-3">
            <Button color="green" onclick={() => void runJobAction('poll-start', startPoll)} disabled={actionState !== '' || dashboard.pipeline.running}>{actionState === 'poll-start' ? 'Startet …' : 'Posteingang prüfen'}</Button>
            <Button color="blue" onclick={() => void runJobAction('poll-all-start', startPollAll)} disabled={actionState !== '' || dashboard.pipeline.running}>{actionState === 'poll-all-start' ? 'Startet …' : 'Alle Dokumente prüfen'}</Button>
            <Button color="alternative" onclick={() => void runJobAction('poll-cancel', cancelPoll)} disabled={actionState !== '' || !dashboard.pipeline.running}>{actionState === 'poll-cancel' ? 'Stoppt …' : 'Polling stoppen'}</Button>
          </div>
        </Card>

        <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embeddings</p>
              <h2 class="mt-2 text-lg font-semibold text-white">Reindex starten</h2>
              <p class="mt-1.5 text-sm text-slate-400">Erstellt den Embedding-Index neu und aktualisiert semantische Suche und Kontextdaten.</p>
            </div>
            <Badge color={dashboard.reindex.running ? 'purple' : 'green'}>{dashboard.reindex.running ? 'Aktiv' : 'Bereit'}</Badge>
          </div>

          <div class="mt-5 rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
            <div class="mb-2 flex items-center justify-between text-sm text-slate-300"><span>Fortschritt</span><span>{dashboard.reindex.total > 0 ? `${dashboard.reindex.done}/${dashboard.reindex.total}` : 'Kein aktiver Lauf'}</span></div>
            <Progressbar progress={reindexPct()} color="purple" />
            <div class="mt-3 grid gap-3 text-sm text-slate-400 sm:grid-cols-2"><div>Fehler: <span class="text-slate-200">{dashboard.reindex.failed}</span></div><div>Index: <span class="text-slate-200">{dashboard.health.embedding_index_ready ? 'Bereit' : 'Fehlt'}</span></div></div>
          </div>

          <div class="mt-5 flex flex-wrap gap-3">
            <Button color="purple" onclick={() => void runJobAction('reindex-start', startReindex)} disabled={actionState !== '' || dashboard.reindex.running}>{actionState === 'reindex-start' ? 'Startet …' : 'Reindex starten'}</Button>
            <Button color="alternative" onclick={() => void runJobAction('reindex-cancel', cancelReindexJob)} disabled={actionState !== '' || !dashboard.reindex.running}>{actionState === 'reindex-cancel' ? 'Stoppt …' : 'Reindex stoppen'}</Button>
          </div>
        </Card>
      </div>

      <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20 xl:sticky xl:top-24 xl:self-start">
        <div class="flex items-center justify-between gap-3">
          <div><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Betriebsstatus</p><h2 class="mt-2 text-lg font-semibold text-white">Systembereitschaft</h2></div>
          <Badge color="gray">{status.app.frontend.mode}</Badge>
        </div>
        <div class="mt-5 space-y-2.5 text-sm">
          <div class={`rounded-2xl border p-3.5 ${readinessTone(status.services.paperless.configured)}`}><div class="text-xs uppercase tracking-[0.2em] opacity-70">Paperless</div><div class="mt-2 font-medium">{status.services.paperless.configured ? 'Konfiguriert und ansprechbar' : 'Nicht konfiguriert'}</div></div>
          <div class={`rounded-2xl border p-3.5 ${readinessTone(status.services.ollama.configured)}`}><div class="text-xs uppercase tracking-[0.2em] opacity-70">Ollama</div><div class="mt-2 font-medium">{status.services.ollama.configured ? 'Konfiguriert und ansprechbar' : 'Nicht konfiguriert'}</div></div>
          <div class={`rounded-2xl border p-3.5 ${readinessTone(dashboard.health.embedding_index_ready)}`}><div class="text-xs uppercase tracking-[0.2em] opacity-70">Embedding-Index</div><div class="mt-2 font-medium">{dashboard.health.embedding_index_ready ? 'Bereit für Suche und Kontext' : 'Index fehlt oder ist noch leer'}</div></div>
          <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5 text-slate-300"><div class="text-xs uppercase tracking-[0.2em] text-slate-500">Logging</div><div class="mt-2 font-medium text-white">{status.logging.level}</div></div>
        </div>
      </Card>
    </div>

    <Card size="xl" class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Job-Protokoll</p>
          <h2 class="mt-2 text-lg font-semibold text-white">Dokumentgenauer Ablauf</h2>
          <p class="mt-1.5 text-sm text-slate-400">Sichere, strukturierte Events aus dem Backend. Wird alle 5 Sekunden aktualisiert und bleibt in SQLite gespeichert.</p>
        </div>
        <Button color="dark" class="rounded-xl border border-slate-700" onclick={() => void refreshProcessing()} disabled={refreshing}>{refreshing ? 'Aktualisiert …' : 'Aktualisieren'}</Button>
      </div>

      <div class="mt-5 space-y-2.5">
        {#each jobEvents as event}
          <div class={`rounded-2xl border p-3.5 text-sm ${event.level === 'error' ? 'border-rose-500/20 bg-rose-500/10 text-rose-100' : event.level === 'warning' ? 'border-amber-500/20 bg-amber-500/10 text-amber-100' : event.level === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-slate-800 bg-slate-950/60 text-slate-300'}`}>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <Badge color={event.level === 'error' ? 'red' : event.level === 'warning' ? 'yellow' : event.level === 'success' ? 'green' : 'gray'}>{event.phase || event.job_type}</Badge>
                  {#if event.document_id}<span class="font-medium text-white">Dokument #{event.document_id}</span>{/if}
                </div>
                <p class="mt-2 text-slate-200">{event.message}</p>
              </div>
              <span class="shrink-0 text-xs text-slate-500">{formatDateTime(event.created_at)}</span>
            </div>
          </div>
        {:else}
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">Noch kein aktives Job-Protokoll. Starte „Posteingang prüfen“, „Alle Dokumente prüfen“ oder „Reindex starten“.</div>
        {/each}
      </div>
    </Card>

    <Card size="xl" class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Audit & Fehler</p>
          <h2 class="mt-2 text-lg font-semibold text-white">Aktuelle Ereignisse</h2>
          <p class="mt-1.5 text-sm text-slate-400">Kompakte Übersicht aus Fehlern und Audit-Aktivität.</p>
        </div>
      </div>

      <div class="mt-5 space-y-2.5">
        {#each logEntries as entry}
          <div class={`rounded-2xl border p-3.5 text-sm ${entry.tone === 'rose' ? 'border-rose-500/20 bg-rose-500/10 text-rose-100' : 'border-slate-800 bg-slate-950/60 text-slate-300'}`}>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <Badge color={entry.tone === 'rose' ? 'red' : 'gray'}>{entry.kind}</Badge>
                  <span class="font-medium text-white">{entry.title}</span>
                </div>
                <p class="mt-2 text-slate-300">{entry.details}</p>
              </div>
              <span class="shrink-0 text-xs text-slate-500">{formatDateTime(entry.occurred_at)}</span>
            </div>
          </div>
        {:else}
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">Noch keine Ereignisse im Live Log.</div>
        {/each}
      </div>
    </Card>
  {/snippet}
</AppShell>
