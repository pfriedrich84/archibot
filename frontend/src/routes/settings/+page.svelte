<script lang="ts">
  import { Badge, Button, Card, Progressbar } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import { cancelPoll, cancelReindexJob, startPoll, startReindex } from '$lib/api';
  import type { DashboardPayload, StatusPayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  let dashboard = $state<DashboardPayload>(undefined as unknown as DashboardPayload);
  let status = $state<StatusPayload>(undefined as unknown as StatusPayload);
  let actionState = $state<'poll-start' | 'poll-cancel' | 'reindex-start' | 'reindex-cancel' | ''>('');
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let initialized = $state(false);

  $effect(() => {
    if (!initialized) {
      dashboard = data.dashboard;
      status = data.status;
      initialized = true;
    }
  });

  function pollPct() {
    return dashboard.pipeline.total > 0 ? Math.round((dashboard.pipeline.done / dashboard.pipeline.total) * 100) : 0;
  }

  function reindexPct() {
    return dashboard.reindex.total > 0 ? Math.round((dashboard.reindex.done / dashboard.reindex.total) * 100) : 0;
  }

  async function runJobAction(
    key: 'poll-start' | 'poll-cancel' | 'reindex-start' | 'reindex-cancel',
    action: () => Promise<DashboardPayload['pipeline'] | DashboardPayload['reindex']>
  ) {
    actionState = key;
    feedback = null;
    try {
      const result = await action();
      if (key.startsWith('poll')) {
        dashboard = { ...dashboard, pipeline: result as DashboardPayload['pipeline'] };
      } else {
        dashboard = { ...dashboard, reindex: result as DashboardPayload['reindex'] };
      }
      feedback = {
        type: 'success',
        message:
          key === 'poll-start'
            ? 'Polling gestartet.'
            : key === 'poll-cancel'
              ? 'Polling-Abbruch angefordert.'
              : key === 'reindex-start'
                ? 'Reindex gestartet.'
                : 'Reindex-Abbruch angefordert.'
      };
    } catch (error) {
      feedback = {
        type: 'error',
        message: error instanceof Error ? error.message : 'Aktion fehlgeschlagen.'
      };
    } finally {
      actionState = '';
    }
  }
</script>

<AppShell title="Einstellungen & Jobs" subtitle="Wichtige Laufzeitwerte prüfen, Polling anstoßen und Embedding-Reindex direkt aus der Admin-Oberfläche starten.">
  {#snippet children()}
    {#if initialized}
    {#if feedback}
      <div class={`rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
        {feedback.message}
      </div>
    {/if}

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Polling</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Dokumente verarbeiten</h2>
          </div>
          <Badge color={dashboard.pipeline.running ? 'blue' : 'green'}>{dashboard.pipeline.running ? 'Aktiv' : 'Bereit'}</Badge>
        </div>

        <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
          <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
            <span>Fortschritt</span>
            <span>{dashboard.pipeline.total > 0 ? `${dashboard.pipeline.done}/${dashboard.pipeline.total}` : 'Kein aktiver Lauf'}</span>
          </div>
          <Progressbar progress={pollPct()} color="blue" />
          <div class="mt-4 grid gap-3 sm:grid-cols-2 text-sm text-slate-400">
            <div>Phase: <span class="text-slate-200">{dashboard.pipeline.phase || 'prepare'}</span></div>
            <div>Fehler: <span class="text-slate-200">{dashboard.pipeline.failed}</span></div>
          </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
          <Button color="green" onclick={() => void runJobAction('poll-start', startPoll)} disabled={actionState !== '' || dashboard.pipeline.running}>
            {actionState === 'poll-start' ? 'Startet …' : 'Polling starten'}
          </Button>
          <Button color="alternative" onclick={() => void runJobAction('poll-cancel', cancelPoll)} disabled={actionState !== '' || !dashboard.pipeline.running}>
            {actionState === 'poll-cancel' ? 'Stoppt …' : 'Polling stoppen'}
          </Button>
        </div>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embeddings</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Reindex starten</h2>
          </div>
          <Badge color={dashboard.reindex.running ? 'purple' : 'green'}>{dashboard.reindex.running ? 'Aktiv' : 'Bereit'}</Badge>
        </div>

        <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
          <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
            <span>Fortschritt</span>
            <span>{dashboard.reindex.total > 0 ? `${dashboard.reindex.done}/${dashboard.reindex.total}` : 'Kein aktiver Lauf'}</span>
          </div>
          <Progressbar progress={reindexPct()} color="purple" />
          <div class="mt-4 grid gap-3 sm:grid-cols-2 text-sm text-slate-400">
            <div>Fehler: <span class="text-slate-200">{dashboard.reindex.failed}</span></div>
            <div>Index: <span class="text-slate-200">{dashboard.health.embedding_index_ready ? 'Bereit' : 'Fehlt'}</span></div>
          </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
          <Button color="purple" onclick={() => void runJobAction('reindex-start', startReindex)} disabled={actionState !== '' || dashboard.reindex.running}>
            {actionState === 'reindex-start' ? 'Startet …' : 'Reindex starten'}
          </Button>
          <Button color="alternative" onclick={() => void runJobAction('reindex-cancel', cancelReindexJob)} disabled={actionState !== '' || !dashboard.reindex.running}>
            {actionState === 'reindex-cancel' ? 'Stoppt …' : 'Reindex stoppen'}
          </Button>
        </div>
      </Card>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.85fr,1.15fr]">
      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Betriebsstatus</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">System</h2>
          </div>
          <Badge color="gray">{status.app.frontend.mode}</Badge>
        </div>

        <div class="mt-6 space-y-3">
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm">
            <div class="text-slate-400">Paperless</div>
            <div class="mt-1 font-medium text-white">{status.services.paperless.configured ? 'Konfiguriert' : 'Nicht konfiguriert'}</div>
          </div>
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm">
            <div class="text-slate-400">Ollama</div>
            <div class="mt-1 font-medium text-white">{status.services.ollama.configured ? 'Konfiguriert' : 'Nicht konfiguriert'}</div>
          </div>
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm">
            <div class="text-slate-400">Log Level</div>
            <div class="mt-1 font-medium text-white">{status.logging.level}</div>
          </div>
        </div>
      </Card>

      <div class="space-y-6">
        {#each data.schema.categories as category}
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Settings Category</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">{category.name}</h2>
              </div>
              <Badge color="gray">{category.fields.length} Felder</Badge>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
              {#each category.fields as field}
                <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="font-medium text-white">{field.label}</p>
                      <p class="mt-1 text-xs text-slate-500">{field.name}</p>
                    </div>
                    {#if field.restart}
                      <Badge color="yellow">Restart</Badge>
                    {:else}
                      <Badge color="green">Live</Badge>
                    {/if}
                  </div>

                  <p class="mt-3 text-sm text-slate-400">{field.help}</p>
                  <div class="mt-4 text-sm text-slate-300">
                    {#if field.sensitive}
                      {#if field.configured}
                        <span class="text-emerald-300">Konfiguriert</span>
                      {:else}
                        <span class="text-amber-300">Nicht gesetzt</span>
                      {/if}
                    {:else}
                      <code class="break-all text-emerald-300">{String(field.value)}</code>
                    {/if}
                  </div>
                </div>
              {/each}
            </div>
          </Card>
        {/each}
      </div>
    </div>
    {/if}
  {/snippet}
</AppShell>
