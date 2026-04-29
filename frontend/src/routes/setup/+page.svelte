<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import { saveSettings, testPaperlessConnection } from '$lib/api';
  import type { OllamaModelOption, PaperlessTagOption } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  const initialData = () => data;

  type SetupForm = {
    paperless_url: string;
    paperless_token: string;
    paperless_inbox_tag_id: number;
    paperless_processed_tag_id: number;
    ocr_requested_tag_id: number;
    ollama_url: string;
    ollama_model: string;
    ollama_embed_model: string;
    ocr_mode: string;
    auto_commit_confidence: number;
    enable_telegram: boolean;
    enable_judge_verification: boolean;
  };

  const steps = [
    { id: 'welcome', label: 'Start', title: 'Willkommen' },
    { id: 'paperless', label: 'Paperless', title: 'Paperless verbinden' },
    { id: 'inbox', label: 'Inbox', title: 'Posteingang festlegen' },
    { id: 'ollama', label: 'Ollama', title: 'Modelle konfigurieren' },
    { id: 'optional', label: 'Optional', title: 'Automation & Extras' },
    { id: 'finish', label: 'Fertig', title: 'Zusammenfassung' }
  ] as const;

  let currentStep = $state(0);
  let saving = $state(false);
  let feedback = $state<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);
  let testingPaperless = $state(false);
  let paperlessTags = $state<PaperlessTagOption[]>(initialData().paperlessTags.items);
  let ollamaModels = $derived<OllamaModelOption[]>(data.ollamaModels.items);

  function schemaField(name: string) {
    for (const category of data.schema.categories) {
      const field = category.fields.find((candidate: { name: string }) => candidate.name === name);
      if (field) return field;
    }
    return null;
  }

  function fieldValue(name: string) {
    return schemaField(name)?.value ?? '';
  }

  function fieldConfigured(name: string) {
    return Boolean(schemaField(name)?.configured);
  }

  let form = $state<SetupForm>({
    paperless_url: String(fieldValue('paperless_url') ?? ''),
    paperless_token: '',
    paperless_inbox_tag_id: Number(fieldValue('paperless_inbox_tag_id') || 0),
    paperless_processed_tag_id: Number(fieldValue('paperless_processed_tag_id') || 0),
    ocr_requested_tag_id: Number(fieldValue('ocr_requested_tag_id') || 0),
    ollama_url: String(fieldValue('ollama_url') ?? 'http://ollama:11434'),
    ollama_model: String(fieldValue('ollama_model') ?? 'gemma4:e4b'),
    ollama_embed_model: String(fieldValue('ollama_embed_model') ?? 'qwen3-embedding:4b'),
    ocr_mode: String(fieldValue('ocr_mode') ?? 'off'),
    auto_commit_confidence: Number(fieldValue('auto_commit_confidence') || 0),
    enable_telegram: Boolean(fieldValue('enable_telegram') ?? false),
    enable_judge_verification: Boolean(fieldValue('enable_judge_verification') ?? false)
  });

  let paperlessTokenConfigured = $derived(fieldConfigured('paperless_token'));
  let paperlessReady = $derived(Boolean(String(form.paperless_url).trim() && (String(form.paperless_token).trim() || paperlessTokenConfigured)));
  let inboxReady = $derived(Number(form.paperless_inbox_tag_id) > 0);
  let ollamaReady = $derived(Boolean(String(form.ollama_url).trim() && String(form.ollama_model).trim() && String(form.ollama_embed_model).trim()));
  let canFinish = $derived(paperlessReady && inboxReady && ollamaReady);

  function modelOptionsFor(currentValue: string) {
    const names = new Set(ollamaModels.map((model) => model.name).filter(Boolean));
    if (currentValue) names.add(currentValue);
    return [...names].sort();
  }

  function validateStep(index: number) {
    if (index === 1) return paperlessReady;
    if (index === 2) return inboxReady;
    if (index === 3) return ollamaReady;
    return true;
  }

  async function testPaperless() {
    if (!paperlessReady) {
      feedback = { type: 'error', message: 'Bitte fülle Paperless URL und API Token aus.' };
      return false;
    }

    testingPaperless = true;
    feedback = null;
    try {
      const response = await testPaperlessConnection(form.paperless_url, form.paperless_token);
      if (!response.ok) {
        feedback = { type: 'error', message: response.error || 'Paperless-Verbindung fehlgeschlagen.' };
        return false;
      }
      paperlessTags = response.items;
      feedback = { type: 'success', message: `${response.items.length} Paperless-Tags geladen.` };
      return true;
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Paperless-Verbindung fehlgeschlagen.' };
      return false;
    } finally {
      testingPaperless = false;
    }
  }

  async function next() {
    feedback = null;
    if (!validateStep(currentStep)) {
      feedback = { type: 'error', message: 'Bitte fülle die Pflichtfelder dieses Schritts aus.' };
      return;
    }
    if (currentStep === 1 && !(await testPaperless())) {
      return;
    }
    currentStep = Math.min(currentStep + 1, steps.length - 1);
  }

  function previous() {
    feedback = null;
    currentStep = Math.max(currentStep - 1, 0);
  }

  async function finishSetup() {
    if (!canFinish) {
      feedback = { type: 'error', message: 'Setup kann erst abgeschlossen werden, wenn Paperless, Inbox Tag und Ollama konfiguriert sind.' };
      return;
    }

    saving = true;
    feedback = null;
    try {
      const updates: Partial<SetupForm> = { ...form };
      if (!String(form.paperless_token).trim() && paperlessTokenConfigured) {
        delete updates.paperless_token;
      }
      await saveSettings(updates);
      feedback = { type: 'success', message: 'Setup gespeichert. Du kannst jetzt zum Dashboard wechseln.' };
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Setup konnte nicht gespeichert werden.' };
    } finally {
      saving = false;
    }
  }

  function stepState(index: number) {
    if (index < currentStep) return 'done';
    if (index === currentStep) return 'active';
    return 'todo';
  }
</script>

<AppShell title="Setup" subtitle="Geführte Ersteinrichtung für Paperless, Inbox Tag und Ollama — ohne manuelles Bearbeiten von .env-Dateien.">
  {#snippet children()}
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Setup Wizard</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">{steps[currentStep].title}</h2>
          </div>
          <Badge color={data.schema.setup_complete ? 'green' : 'yellow'}>
            {data.schema.setup_complete ? 'Bereits abgeschlossen' : 'Setup erforderlich'}
          </Badge>
        </div>

        <div class="mt-6 grid gap-2 sm:grid-cols-3 xl:grid-cols-6" aria-label="Setup-Schritte">
          {#each steps as step, index}
            <button
              type="button"
              onclick={() => (currentStep = index)}
              class={`rounded-2xl border p-3 text-left transition ${
                stepState(index) === 'active'
                  ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-50'
                  : stepState(index) === 'done'
                    ? 'border-sky-500/25 bg-sky-500/10 text-sky-100'
                    : 'border-slate-800 bg-slate-950/50 text-slate-400 hover:border-slate-700'
              }`}
            >
              <span class="block text-[11px] uppercase tracking-wide opacity-75">Schritt {index + 1}</span>
              <span class="mt-1 block text-sm font-medium">{step.label}</span>
            </button>
          {/each}
        </div>

        {#if feedback}
          <div class={`mt-5 rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : feedback.type === 'info' ? 'border-sky-500/20 bg-sky-500/10 text-sky-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
            {feedback.message}
          </div>
        {/if}

        <div class="mt-6">
          {#if currentStep === 0}
            <EmptyState icon="🗂️" title="ArchiBot ordnet Paperless-Dokumente vor" description="Dieser Wizard speichert die notwendigen Verbindungsdaten, legt den Posteingang fest und bereitet Ollama-Modelle für Klassifikation und Embeddings vor." />
          {:else if currentStep === 1}
            <div class="grid gap-4">
              <label class="grid gap-2 text-sm text-slate-300">Paperless URL<input bind:value={form.paperless_url} class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50" placeholder="http://paperless:8000" /></label>
              <label class="grid gap-2 text-sm text-slate-300">API Token<input bind:value={form.paperless_token} type="password" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50" placeholder={paperlessTokenConfigured ? 'Bereits gespeichert — leer lassen zum Beibehalten' : 'Token aus Paperless'} /></label>
              <div class="flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <span>Status laut Backend: {data.status.services.paperless.configured ? 'Paperless ist konfiguriert.' : 'Noch nicht konfiguriert.'}</span>
                <Button color="alternative" class="rounded-xl" disabled={!paperlessReady || testingPaperless} onclick={() => void testPaperless()}>{testingPaperless ? 'Prüft …' : 'Verbindung testen & Tags laden'}</Button>
              </div>
            </div>
          {:else if currentStep === 2}
            <div class="grid gap-4 md:grid-cols-3">
              <label class="grid gap-2 text-sm text-slate-300">
                Inbox Tag ID
                <select bind:value={form.paperless_inbox_tag_id} aria-label="Inbox Tag ID" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50">
                  <option value={0}>No tag selected</option>
                  {#each paperlessTags as tag}
                    <option value={tag.id}>{tag.name} (#{tag.id})</option>
                  {/each}
                </select>
              </label>
              <label class="grid gap-2 text-sm text-slate-300">
                Processed Tag ID
                <select bind:value={form.paperless_processed_tag_id} aria-label="Processed Tag ID" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50">
                  <option value={0}>No processed tag</option>
                  {#each paperlessTags as tag}
                    <option value={tag.id}>{tag.name} (#{tag.id})</option>
                  {/each}
                </select>
              </label>
              <label class="grid gap-2 text-sm text-slate-300">
                OCR Tag ID
                <select bind:value={form.ocr_requested_tag_id} aria-label="OCR Tag ID" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50">
                  <option value={0}>No OCR filter</option>
                  {#each paperlessTags as tag}
                    <option value={tag.id}>{tag.name} (#{tag.id})</option>
                  {/each}
                </select>
              </label>
              <p class="text-sm text-slate-400 md:col-span-3">Die gespeicherten Tag-IDs werden aus Paperless geladen und vorausgewählt. Nur der Inbox Tag ist Pflicht.</p>
            </div>
          {:else if currentStep === 3}
            <div class="grid gap-4 md:grid-cols-2">
              <label class="grid gap-2 text-sm text-slate-300 md:col-span-2">Ollama URL<input bind:value={form.ollama_url} class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50" /></label>
              <label class="grid gap-2 text-sm text-slate-300">
                Klassifikationsmodell
                <select bind:value={form.ollama_model} aria-label="Klassifikationsmodell" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50">
                  {#each modelOptionsFor(form.ollama_model) as name}
                    <option value={name}>{name}</option>
                  {/each}
                </select>
              </label>
              <label class="grid gap-2 text-sm text-slate-300">
                Embedding-Modell
                <select bind:value={form.ollama_embed_model} aria-label="Embedding-Modell" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50">
                  {#each modelOptionsFor(form.ollama_embed_model) as name}
                    <option value={name}>{name}</option>
                  {/each}
                </select>
              </label>
            </div>
          {:else if currentStep === 4}
            <div class="grid gap-4 md:grid-cols-2">
              <label class="grid gap-2 text-sm text-slate-300">OCR Modus<select bind:value={form.ocr_mode} class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50"><option value="off">Aus</option><option value="text">Text</option><option value="vision_light">Vision light</option><option value="vision_full">Vision full</option></select></label>
              <label class="grid gap-2 text-sm text-slate-300">Auto-Commit ab Konfidenz<input bind:value={form.auto_commit_confidence} type="number" min="0" max="100" class="rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-white outline-none focus:border-emerald-500/50" /></label>
              <label class="flex items-center gap-3 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300"><input bind:checked={form.enable_telegram} type="checkbox" class="rounded" /> Telegram aktivieren</label>
              <label class="flex items-center gap-3 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300"><input bind:checked={form.enable_judge_verification} type="checkbox" class="rounded" /> LLM-as-Judge aktivieren</label>
            </div>
          {:else}
            <div class="space-y-4">
              <div class="grid gap-3 md:grid-cols-3">
                <div class={`rounded-2xl border p-4 ${paperlessReady ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>Paperless<br /><strong>{paperlessReady ? 'bereit' : 'fehlt'}</strong></div>
                <div class={`rounded-2xl border p-4 ${inboxReady ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>Inbox Tag<br /><strong>{inboxReady ? form.paperless_inbox_tag_id : 'fehlt'}</strong></div>
                <div class={`rounded-2xl border p-4 ${ollamaReady ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>Ollama<br /><strong>{ollamaReady ? 'bereit' : 'fehlt'}</strong></div>
              </div>
              <Button color="green" class="rounded-xl" disabled={!canFinish || saving} onclick={() => void finishSetup()}>{saving ? 'Speichert …' : 'Setup abschließen'}</Button>
              {#if feedback?.type === 'success'}<a href="/app" class="ml-3 inline-flex text-sm font-medium text-emerald-300 hover:text-emerald-200">Zum Dashboard →</a>{/if}
            </div>
          {/if}
        </div>

        <div class="mt-6 flex items-center justify-between border-t border-slate-800 pt-5">
          <Button color="alternative" class="rounded-xl" disabled={currentStep === 0} onclick={previous}>Zurück</Button>
          {#if currentStep < steps.length - 1}
            <Button color="green" class="rounded-xl" disabled={testingPaperless} onclick={() => void next()}>{testingPaperless ? 'Prüft …' : 'Weiter'}</Button>
          {:else}
            <Button color="green" class="rounded-xl" disabled={!canFinish || saving} onclick={() => void finishSetup()}>{saving ? 'Speichert …' : 'Abschließen'}</Button>
          {/if}
        </div>
      </Card>

      <aside class="space-y-4">
        <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Bereitschaft</p>
          <div class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between gap-3"><span class="text-slate-400">Paperless</span><Badge color={paperlessReady ? 'green' : 'yellow'}>{paperlessReady ? 'OK' : 'Offen'}</Badge></div>
            <div class="flex justify-between gap-3"><span class="text-slate-400">Inbox Tag</span><Badge color={inboxReady ? 'green' : 'yellow'}>{inboxReady ? 'OK' : 'Offen'}</Badge></div>
            <div class="flex justify-between gap-3"><span class="text-slate-400">Ollama</span><Badge color={ollamaReady ? 'green' : 'yellow'}>{ollamaReady ? 'OK' : 'Offen'}</Badge></div>
          </div>
        </Card>
      </aside>
    </div>
  {/snippet}
</AppShell>
