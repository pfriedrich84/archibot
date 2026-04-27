<script lang="ts">
  import { Card, Progressbar, Badge } from 'flowbite-svelte';
  import type { DashboardPayload, StatusPayload } from '$lib/types';

  let { dashboard, status } = $props<{ dashboard: DashboardPayload; status: StatusPayload }>();

  const pollPct = () =>
    dashboard.pipeline.total > 0 ? Math.round((dashboard.pipeline.done / dashboard.pipeline.total) * 100) : 0;
  const reindexPct = () =>
    dashboard.reindex.total > 0 ? Math.round((dashboard.reindex.done / dashboard.reindex.total) * 100) : 0;

  function formatDateTime(value: string | null) {
    if (!value) return 'nicht geplant';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    }).format(date);
  }

  function phaseLabel(value: string | null) {
    if (!value || value === 'idle') return 'Bereit';
    if (value === 'prepare') return 'Vorbereitung';
    if (value === 'ocr') return 'OCR';
    if (value === 'embed') return 'Embedding';
    if (value === 'classify') return 'Klassifikation';
    return value;
  }

  function progressLabel(done: number, total: number) {
    return total > 0 ? `${done}/${total}` : 'Kein aktiver Lauf';
  }
</script>

<div class="grid gap-6 xl:grid-cols-[1.45fr,0.95fr]">
  <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
    <div class="flex items-start justify-between gap-4">
      <div>
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pipeline</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">{dashboard.pipeline.running ? 'Polling aktiv' : 'Pipeline bereit'}</h2>
        <p class="mt-2 text-sm text-slate-400">Nächster Poll: {formatDateTime(dashboard.pipeline.next_run_at)}</p>
      </div>
      <Badge color={dashboard.pipeline.running ? 'blue' : 'green'}>{phaseLabel(dashboard.pipeline.phase)}</Badge>
    </div>

    <div class="mt-6 space-y-5">
      <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4">
        <div class="mb-2 flex justify-between text-sm text-slate-300">
          <span>Polling</span>
          <span>{progressLabel(dashboard.pipeline.done, dashboard.pipeline.total || 0)}</span>
        </div>
        <Progressbar progress={pollPct()} color="blue" />
      </div>

      <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4">
        <div class="mb-2 flex justify-between text-sm text-slate-300">
          <span>Reindex</span>
          <span>{progressLabel(dashboard.reindex.done, dashboard.reindex.total || 0)}</span>
        </div>
        <Progressbar progress={reindexPct()} color="purple" />
      </div>

      <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">OCR</p>
          <p class="mt-2 text-lg font-semibold text-white">{dashboard.health.ocr_mode}</p>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Auto-Commit</p>
          <p class="mt-2 text-lg font-semibold text-white">{dashboard.health.auto_commit_confidence}%</p>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Embedding Index</p>
          <p class="mt-2 text-lg font-semibold text-white">{dashboard.health.embedding_index_ready ? 'Bereit' : 'Fehlt'}</p>
        </div>
      </div>
    </div>
  </Card>

  <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Runtime & Logs</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">Betrieb</h2>
      </div>
      <Badge color="gray">Live</Badge>
    </div>

    <div class="mt-6 grid gap-3">
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Rendering</p>
        <p class="mt-1 font-medium text-white">{status.app.frontend.rendering === 'hybrid' ? 'Hybrid' : status.app.frontend.rendering}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Log Level</p>
        <p class="mt-1 font-medium text-white">{status.logging.level}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Strukturierte Logs</p>
        <p class="mt-1 font-medium text-white">{status.logging.structured_logs ? 'Aktiv' : 'Inaktiv'}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Legacy UI</p>
        <p class="mt-1 font-medium text-white">{status.app.legacy_ui.deprecated ? 'Veraltet, noch aktiv' : 'Aktiv'}</p>
      </div>
    </div>
  </Card>
</div>
