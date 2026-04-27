<script lang="ts">
  import { Card, Progressbar, Badge } from 'flowbite-svelte';
  import type { DashboardPayload, StatusPayload } from '$lib/types';

  let { dashboard, status } = $props<{ dashboard: DashboardPayload; status: StatusPayload }>();

  const pollPct = () =>
    dashboard.pipeline.total > 0 ? Math.round((dashboard.pipeline.done / dashboard.pipeline.total) * 100) : 0;
  const reindexPct = () =>
    dashboard.reindex.total > 0 ? Math.round((dashboard.reindex.done / dashboard.reindex.total) * 100) : 0;
</script>

<div class="grid gap-6 xl:grid-cols-[1.3fr,0.9fr]">
  <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
    <div class="flex items-center justify-between gap-4">
      <div>
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pipeline Status</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">{dashboard.pipeline.running ? 'Polling aktiv' : 'Pipeline idle'}</h2>
        <p class="mt-2 text-sm text-slate-400">Nächster Poll: {dashboard.pipeline.next_run_at ?? 'nicht geplant'}</p>
      </div>
      <Badge color={dashboard.pipeline.running ? 'blue' : 'green'}>{dashboard.pipeline.phase || 'idle'}</Badge>
    </div>

    <div class="mt-6 space-y-6">
      <div>
        <div class="mb-2 flex justify-between text-sm text-slate-300">
          <span>Polling</span>
          <span>{dashboard.pipeline.done}/{dashboard.pipeline.total || 0}</span>
        </div>
        <Progressbar progress={pollPct()} color="blue" />
      </div>

      <div>
        <div class="mb-2 flex justify-between text-sm text-slate-300">
          <span>Reindex</span>
          <span>{dashboard.reindex.done}/{dashboard.reindex.total || 0}</span>
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
          <p class="mt-2 text-lg font-semibold text-white">{dashboard.health.embedding_index_ready ? 'bereit' : 'fehlt'}</p>
        </div>
      </div>
    </div>
  </Card>

  <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Runtime / Logs</p>
    <h2 class="mt-2 text-2xl font-semibold text-white">Status & Operability</h2>

    <div class="mt-6 space-y-4">
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Rendering</p>
        <p class="mt-1 font-medium text-white">{status.app.frontend.rendering}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Log Level</p>
        <p class="mt-1 font-medium text-white">{status.logging.level}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Structured Logs</p>
        <p class="mt-1 font-medium text-white">{status.logging.structured_logs ? 'aktiv' : 'Console debug mode'}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Legacy UI</p>
        <p class="mt-1 font-medium text-white">{status.app.legacy_ui.deprecated ? 'deprecated, still active' : 'active'}</p>
      </div>
    </div>
  </Card>
</div>
