<script lang="ts">
  import { Badge, Button, Card, Progressbar } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import { cancelPoll, cancelReindexJob, saveSettings, startPoll, startReindex } from '$lib/api';
  import type { DashboardPayload, SettingsSchemaPayload, StatusPayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  const initialData = () => data;
  let dashboard = $state<DashboardPayload>(initialData().dashboard);
  let status = $state<StatusPayload>(initialData().status);
  let schema = $state<SettingsSchemaPayload>(initialData().schema);
  type SettingValue = string | number | boolean;

  let actionState = $state<'poll-start' | 'poll-cancel' | 'reindex-start' | 'reindex-cancel' | 'settings-save' | ''>('');
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let draftSettings = $state<Record<string, SettingValue>>({});
  let originalSettings = $state<Record<string, SettingValue>>({});
  let initialized = $state(false);
  let search = $state('');

  $effect(() => {
    if (!initialized) {
      dashboard = data.dashboard;
      status = data.status;
      schema = data.schema;
      const values: Record<string, SettingValue> = {};
      for (const category of data.schema.categories) {
        for (const field of category.fields) {
          values[field.name] = field.sensitive ? '' : field.value;
        }
      }
      draftSettings = { ...values };
      originalSettings = { ...values };
      initialized = true;
    }
  });

  let filteredCategories = $derived.by(() => {
    if (!initialized) return [];
    const needle = search.trim().toLowerCase();
    return schema.categories
      .map((category) => ({
        ...category,
        fields: category.fields.filter((field) => {
          if (!needle) return true;
          const haystack = [category.name, field.name, field.label, field.help].join(' ').toLowerCase();
          return haystack.includes(needle);
        })
      }))
      .filter((category) => category.fields.length > 0 || !needle);
  });

  function pollPct() {
    return dashboard.pipeline.total > 0 ? Math.round((dashboard.pipeline.done / dashboard.pipeline.total) * 100) : 0;
  }

  function reindexPct() {
    return dashboard.reindex.total > 0 ? Math.round((dashboard.reindex.done / dashboard.reindex.total) * 100) : 0;
  }

  let changedSettingsCount = $derived.by(() =>
    Object.entries(draftSettings).filter(([key, value]) => String(value) !== String(originalSettings[key] ?? '')).length
  );

  function inputType(type: string) {
    if (type === 'password') return 'password';
    if (type === 'number') return 'number';
    if (type === 'url') return 'url';
    return 'text';
  }

  function updateDraft(name: string, value: string, type: string) {
    if (type === 'number') {
      draftSettings[name] = value === '' ? 0 : Number(value);
    } else {
      draftSettings[name] = value;
    }
  }

  async function saveChangedSettings() {
    const updates: Record<string, SettingValue> = {};
    for (const [key, value] of Object.entries(draftSettings)) {
      if (String(value) !== String(originalSettings[key] ?? '')) updates[key] = value;
    }
    if (Object.keys(updates).length === 0) {
      feedback = { type: 'success', message: 'Keine Änderungen zu speichern.' };
      return;
    }

    actionState = 'settings-save';
    feedback = null;
    try {
      const result = await saveSettings(updates);
      originalSettings = { ...originalSettings, ...updates };
      feedback = {
        type: 'success',
        message: result.restart_required.length > 0
          ? `Gespeichert. Restart erforderlich für: ${result.restart_required.join(', ')}`
          : 'Settings gespeichert und soweit möglich live angewendet.'
      };
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Settings konnten nicht gespeichert werden.' };
    } finally {
      actionState = '';
    }
  }

  function readinessTone(ok: boolean) {
    return ok
      ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100'
      : 'border-amber-500/20 bg-amber-500/10 text-amber-100';
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

<AppShell title="Einstellungen & Jobs" subtitle="Polling, Reindex und Laufzeitstatus an einer Stelle steuern — mit durchsuchbarer Konfigurationsreferenz für schnelles Auffinden einzelner Settings.">
  {#snippet children()}
    {#if initialized}
      {#if feedback}
        <div class={`rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
          {feedback.message}
        </div>
      {/if}

      <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(19rem,0.8fr)]">
        <div class="grid gap-6 xl:grid-cols-2">
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Polling</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Dokumente verarbeiten</h2>
                <p class="mt-1.5 text-sm text-slate-400">Startet den nächsten Erfassungs- und Klassifizierungslauf für neue Dokumente.</p>
              </div>
              <Badge color={dashboard.pipeline.running ? 'blue' : 'green'}>{dashboard.pipeline.running ? 'Aktiv' : 'Bereit'}</Badge>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3.5">
              <div class="mb-2 flex items-center justify-between text-sm text-slate-300"><span>Fortschritt</span><span>{dashboard.pipeline.total > 0 ? `${dashboard.pipeline.done}/${dashboard.pipeline.total}` : 'Kein aktiver Lauf'}</span></div>
              <Progressbar progress={pollPct()} color="blue" />
              <div class="mt-3 grid gap-3 text-sm text-slate-400 sm:grid-cols-2"><div>Phase: <span class="text-slate-200">{dashboard.pipeline.phase || 'prepare'}</span></div><div>Fehler: <span class="text-slate-200">{dashboard.pipeline.failed}</span></div></div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
              <Button color="green" onclick={() => void runJobAction('poll-start', startPoll)} disabled={actionState !== '' || dashboard.pipeline.running}>{actionState === 'poll-start' ? 'Startet …' : 'Polling starten'}</Button>
              <Button color="alternative" onclick={() => void runJobAction('poll-cancel', cancelPoll)} disabled={actionState !== '' || !dashboard.pipeline.running}>{actionState === 'poll-cancel' ? 'Stoppt …' : 'Polling stoppen'}</Button>
            </div>
          </Card>

          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embeddings</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Reindex starten</h2>
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

        <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20 xl:sticky xl:top-24 xl:self-start">
          <div class="flex items-center justify-between gap-3">
            <div><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Betriebsstatus</p><h2 class="mt-2 text-2xl font-semibold text-white">Systembereitschaft</h2></div>
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

      <Card size="xl" class="mt-6 rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Settings bearbeiten</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Konfiguration schnell finden und speichern</h2>
            <p class="mt-1.5 text-sm text-slate-400">Filtert nach Bereich, Key, Label und Hilfetext. Sensible Werte bleiben geschützt und werden nur aktualisiert, wenn du sie neu eingibst.</p>
          </div>
          <div class="flex w-full max-w-2xl flex-col gap-3 sm:flex-row sm:items-center">
            <input bind:value={search} type="search" placeholder="Nach Setting-Namen, Label oder Hilfetext suchen …" class="min-w-0 flex-1 rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-500/40" />
            <Button color="green" class="rounded-xl" disabled={actionState !== '' || changedSettingsCount === 0} onclick={() => void saveChangedSettings()}>
              {actionState === 'settings-save' ? 'Speichert …' : changedSettingsCount > 0 ? `${changedSettingsCount} speichern` : 'Gespeichert'}
            </Button>
          </div>
        </div>
      </Card>

      <div class="mt-6 space-y-6">
        {#if filteredCategories.length === 0}
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-6 text-sm text-slate-300">Keine Settings passen zur aktuellen Suche.</div>
          </Card>
        {/if}

        {#each filteredCategories as category}
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Konfigurationsbereich</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">{category.name}</h2>
              </div>
              <Badge color="gray">{category.fields.length} Treffer</Badge>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {#each category.fields as field}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/70 p-3.5">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="font-medium text-white">{field.label}</p>
                      <p class="mt-1 text-xs text-slate-500">{field.name}</p>
                    </div>
                    {#if field.restart}
                      <Badge color="yellow">Restart nötig</Badge>
                    {:else}
                      <Badge color="green">Live</Badge>
                    {/if}
                  </div>

                  <p class="mt-2.5 text-sm text-slate-400">{field.help}</p>
                  <div class="mt-3 text-sm text-slate-300">
                    {#if field.input_type === 'bool'}
                      <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-800 bg-slate-900/80 px-3 py-2 text-sm text-slate-200">
                        <input
                          type="checkbox"
                          checked={Boolean(draftSettings[field.name])}
                          onchange={(event) => (draftSettings[field.name] = event.currentTarget.checked)}
                          class="rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500"
                        />
                        {Boolean(draftSettings[field.name]) ? 'Aktiv' : 'Inaktiv'}
                      </label>
                    {:else}
                      <input
                        value={String(draftSettings[field.name] ?? '')}
                        type={inputType(field.input_type)}
                        placeholder={field.sensitive && field.configured ? 'Konfiguriert — leer lassen für unverändert' : ''}
                        oninput={(event) => updateDraft(field.name, event.currentTarget.value, field.input_type)}
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-3 py-2 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-500/40"
                      />
                      {#if field.sensitive}
                        <p class="mt-2 text-xs {field.configured ? 'text-emerald-300' : 'text-amber-300'}">{field.configured ? 'Bereits konfiguriert' : 'Noch nicht gesetzt'}</p>
                      {/if}
                    {/if}
                  </div>
                </div>
              {/each}
            </div>
          </Card>
        {/each}
      </div>
    {/if}
  {/snippet}
</AppShell>
