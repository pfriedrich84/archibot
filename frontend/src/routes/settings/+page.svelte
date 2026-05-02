<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import { resetPrompt, savePrompt, saveSettings } from '$lib/api';
  import type { OllamaModelOption, PaperlessTagOption, PromptItem, SettingsSchemaPayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  const initialData = () => data;
  let schema = $state<SettingsSchemaPayload>(initialData().schema);
  let paperlessTags = $state<PaperlessTagOption[]>(initialData().paperlessTags.items);
  let ollamaModels = $state<OllamaModelOption[]>(initialData().ollamaModels.items);
  let prompts = $state<PromptItem[]>(initialData().prompts.items);
  let promptDrafts = $state<Record<string, string>>({});
  let promptMaxChars = $state<number>(initialData().prompts.max_chars);
  let activePromptKey = $state<string>(initialData().prompts.items[0]?.key ?? '');
  let fieldErrors = $state<Record<string, string>>({});
  type SettingValue = string | number | boolean;

  let actionState = $state<'settings-save' | `prompt-${string}` | ''>('');
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let draftSettings = $state<Record<string, SettingValue>>({});
  let originalSettings = $state<Record<string, SettingValue>>({});
  let initialized = $state(false);
  let search = $state('');

  $effect(() => {
    if (!initialized) {
      schema = data.schema;
      paperlessTags = data.paperlessTags.items;
      ollamaModels = data.ollamaModels.items;
      prompts = data.prompts.items;
      promptMaxChars = data.prompts.max_chars;
      promptDrafts = Object.fromEntries(data.prompts.items.map((prompt: PromptItem) => [prompt.key, prompt.content]));
      activePromptKey = data.prompts.items[0]?.key ?? '';
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


  let changedSettingsCount = $derived.by(() =>
    Object.entries(draftSettings).filter(([key, value]) => String(value) !== String(originalSettings[key] ?? '')).length
  );

  let activePrompt = $derived(prompts.find((prompt) => prompt.key === activePromptKey) ?? prompts[0]);
  let activePromptDraft = $derived(activePrompt ? (promptDrafts[activePrompt.key] ?? '') : '');
  let activePromptChanged = $derived(Boolean(activePrompt && activePromptDraft !== activePrompt.content));

  function inputType(type: string) {
    if (type === 'password') return 'password';
    if (type === 'number') return 'number';
    if (type === 'url') return 'url';
    return 'text';
  }

  function tagFieldLabel(fieldName: string): string {
    if (fieldName === 'paperless_inbox_tag_id') return 'inbox tag';
    if (fieldName === 'paperless_processed_tag_id') return 'processed tag';
    if (fieldName === 'ocr_requested_tag_id') return 'OCR tag';
    return 'tag';
  }

  function emptyTagOptionLabel(fieldName: string): string {
    if (fieldName === 'ocr_requested_tag_id') return 'No OCR filter';
    if (fieldName === 'paperless_processed_tag_id') return 'No processed tag';
    return 'No tag selected';
  }

  function tagFieldError(fieldName: string): string | null {
    if (fieldErrors[fieldName]) return fieldErrors[fieldName];
    const value = Number(draftSettings[fieldName] ?? 0);
    if (value > 0 && !paperlessTags.some((tag) => tag.id === value)) {
      return `Configured ${tagFieldLabel(fieldName)} ID ${value} does not exist in Paperless`;
    }
    return null;
  }

  function tagSelectValue(fieldName: string): number {
    return tagFieldError(fieldName) ? 0 : Number(draftSettings[fieldName] ?? 0);
  }

  function modelOptionsFor(currentValue: string) {
    const names = new Set(ollamaModels.map((model) => model.name).filter(Boolean));
    if (currentValue) names.add(currentValue);
    return [...names].sort();
  }

  function emptyModelOptionLabel(fieldName: string): string {
    if (fieldName === 'ollama_judge_model') return 'Reuse Classification Model';
    if (fieldName === 'ocr_vision_model') return 'Use Classification Model';
    return 'No model selected';
  }

  const ocrModeOptions = [
    { value: 'off', label: 'Off' },
    { value: 'text', label: 'Text' },
    { value: 'vision_light', label: 'Vision light' },
    { value: 'vision_full', label: 'Vision full' }
  ];

  function updateDraft(name: string, value: string, type: string) {
    fieldErrors = { ...fieldErrors, [name]: '' };
    if (type === 'number' || type === 'tag_select' || type === 'slider') {
      draftSettings[name] = value === '' ? 0 : Number(value);
    } else {
      draftSettings[name] = value;
    }
  }

  function sliderValue(field: SettingsSchemaPayload['categories'][number]['fields'][number]): number {
    const value = Number(draftSettings[field.name] ?? field.value ?? 0);
    const min = field.min ?? 0;
    const max = field.max ?? 1;
    if (Number.isNaN(value)) return min;
    return Math.min(max, Math.max(min, value));
  }

  function sliderDisplayValue(field: SettingsSchemaPayload['categories'][number]['fields'][number]): string {
    const value = sliderValue(field);
    return value === 0 ? 'Unlimited / Unbegrenzt' : value.toFixed(1);
  }

  function syncPrompts(items: PromptItem[]) {
    prompts = items;
    promptDrafts = Object.fromEntries(items.map((prompt) => [prompt.key, prompt.content]));
    if (!items.some((prompt) => prompt.key === activePromptKey)) activePromptKey = items[0]?.key ?? '';
  }

  async function saveActivePrompt() {
    if (!activePrompt) return;
    if (activePromptDraft.length > promptMaxChars) {
      feedback = { type: 'error', message: `Prompt ist zu lang (max. ${promptMaxChars} Zeichen).` };
      return;
    }
    actionState = `prompt-${activePrompt.key}`;
    feedback = null;
    try {
      const result = await savePrompt(activePrompt.key, activePromptDraft);
      syncPrompts(result.items);
      feedback = { type: 'success', message: `Prompt „${activePrompt.label}“ gespeichert.` };
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Prompt konnte nicht gespeichert werden.' };
    } finally {
      actionState = '';
    }
  }

  async function resetActivePrompt() {
    if (!activePrompt) return;
    actionState = `prompt-${activePrompt.key}`;
    feedback = null;
    try {
      const result = await resetPrompt(activePrompt.key);
      syncPrompts(result.items);
      feedback = { type: 'success', message: `Prompt „${activePrompt.label}“ auf Standard zurückgesetzt.` };
    } catch (error) {
      feedback = { type: 'error', message: error instanceof Error ? error.message : 'Prompt konnte nicht zurückgesetzt werden.' };
    } finally {
      actionState = '';
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
      fieldErrors = result.field_errors ?? {};
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


</script>

<AppShell title="Einstellungen" subtitle="Durchsuchbare Konfigurationsreferenz für schnelles Auffinden und Speichern einzelner Settings.">
  {#snippet children()}
    {#if initialized}
      {#if feedback}
        <div class={`rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
          {feedback.message}
        </div>
      {/if}

      <Card size="xl" class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Settings bearbeiten</p>
            <h2 class="mt-2 text-lg font-semibold text-white">Konfiguration schnell finden und speichern</h2>
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

      <Card size="xl" class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Prompts bearbeiten</p>
            <h2 class="mt-2 text-lg font-semibold text-white">System-Prompts anpassen</h2>
            <p class="mt-1.5 text-sm text-slate-400">Änderungen werden als Overrides im Data-Verzeichnis gespeichert. Zurücksetzen aktiviert wieder den eingebauten Standard.</p>
          </div>
          {#if activePrompt}
            <div class="flex flex-wrap gap-2">
              <Button color="alternative" class="rounded-xl" disabled={actionState !== '' || !activePrompt.overridden} onclick={() => void resetActivePrompt()}>Standard wiederherstellen</Button>
              <Button color="green" class="rounded-xl" disabled={actionState !== '' || !activePromptChanged || activePromptDraft.length > promptMaxChars} onclick={() => void saveActivePrompt()}>
                {actionState === `prompt-${activePrompt.key}` ? 'Speichert …' : 'Prompt speichern'}
              </Button>
            </div>
          {/if}
        </div>

        {#if activePrompt}
          <div class="mt-5 grid gap-4 lg:grid-cols-[18rem_1fr]">
            <div class="space-y-2">
              {#each prompts as prompt}
                <button
                  type="button"
                  class={`w-full rounded-2xl border px-4 py-3 text-left transition ${prompt.key === activePrompt.key ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/60 hover:border-slate-700'}`}
                  onclick={() => (activePromptKey = prompt.key)}
                >
                  <span class="block font-medium text-white">{prompt.label}</span>
                  <span class="mt-1 block text-xs text-slate-500">{prompt.filename}</span>
                  {#if prompt.overridden}<span class="mt-2 inline-flex rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-200">Override aktiv</span>{/if}
                </button>
              {/each}
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
              <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h3 class="font-semibold text-white">{activePrompt.label}</h3>
                  <p class="mt-1 text-sm text-slate-400">{activePrompt.description}</p>
                </div>
                <Badge color={activePromptChanged ? 'yellow' : activePrompt.overridden ? 'green' : 'gray'}>{activePromptChanged ? 'Geändert' : activePrompt.overridden ? 'Override' : 'Standard'}</Badge>
              </div>
              <textarea
                value={activePromptDraft}
                oninput={(event) => (promptDrafts = { ...promptDrafts, [activePrompt.key]: event.currentTarget.value })}
                class="min-h-[26rem] w-full rounded-2xl border border-slate-700 bg-slate-950/80 p-4 font-mono text-sm text-slate-100 outline-none transition focus:border-emerald-500/40"
                spellcheck="false"
              ></textarea>
              <div class="mt-2 flex justify-between text-xs {activePromptDraft.length > promptMaxChars ? 'text-rose-300' : 'text-slate-500'}">
                <span>{activePromptDraft.length} / {promptMaxChars} Zeichen</span>
                <span>{activePrompt.filename}</span>
              </div>
            </div>
          </div>
        {/if}
      </Card>

      {#if changedSettingsCount > 0}
        <div class="fixed bottom-6 right-6 z-30 rounded-2xl border border-emerald-500/30 bg-slate-950/95 p-3 shadow-2xl shadow-slate-950/60 backdrop-blur">
          <div class="flex items-center gap-3">
            <div class="hidden text-sm sm:block">
              <p class="font-medium text-white">Ungespeicherte Änderungen</p>
              <p class="text-xs text-slate-400">{changedSettingsCount} Setting{changedSettingsCount === 1 ? '' : 's'} geändert</p>
            </div>
            <Button color="green" class="rounded-2xl px-5" disabled={actionState !== ''} onclick={() => void saveChangedSettings()}>
              {actionState === 'settings-save' ? 'Speichert …' : `${changedSettingsCount} speichern`}
            </Button>
          </div>
        </div>
      {/if}

      <div class="mt-4 space-y-6">
        {#if filteredCategories.length === 0}
          <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300">Keine Settings passen zur aktuellen Suche.</div>
          </Card>
        {/if}

        {#each filteredCategories as category}
          <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Konfigurationsbereich</p>
                <h2 class="mt-2 text-lg font-semibold text-white">{category.name}</h2>
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
                    {:else if field.input_type === 'slider'}
                      <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3">
                        <div class="mb-3 flex items-center justify-between gap-3">
                          <span class="text-xs font-medium uppercase tracking-[0.16em] text-slate-500">Threshold / Schwellwert</span>
                          <span class={`rounded-full px-2.5 py-1 text-xs font-semibold ${sliderValue(field) === 0 ? 'bg-amber-500/15 text-amber-200' : 'bg-emerald-500/15 text-emerald-200'}`}>
                            {sliderDisplayValue(field)}
                          </span>
                        </div>
                        <input
                          aria-label={field.label}
                          bind:value={draftSettings[field.name]}
                          type="range"
                          min={field.min ?? 0}
                          max={field.max ?? 1}
                          step={field.step ?? 0.1}
                          oninput={(event) => updateDraft(field.name, event.currentTarget.value, field.input_type)}
                          class="w-full accent-emerald-500"
                        />
                        <div class="mt-2 flex justify-between text-[11px] text-slate-500">
                          <span>0 = Unlimited / Unbegrenzt</span>
                          <span>1.0 = broad / breit</span>
                        </div>
                        {#if sliderValue(field) === 0}
                          <p class="mt-2 rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">Unlimited / Unbegrenzt: no distance threshold is applied. Context Max Docs can still limit the final context.</p>
                        {/if}
                      </div>
                    {:else if field.input_type === 'tag_select'}
                      <select
                        aria-label={field.label}
                        value={tagSelectValue(field.name)}
                        onchange={(event) => updateDraft(field.name, event.currentTarget.value, field.input_type)}
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40"
                      >
                        <option value={0}>{emptyTagOptionLabel(field.name)}</option>
                        {#each paperlessTags as tag}
                          <option value={tag.id}>{tag.name} (#{tag.id})</option>
                        {/each}
                      </select>
                      {#if tagFieldError(field.name)}
                        <p class="mt-2 text-xs text-rose-300">{tagFieldError(field.name)}</p>
                      {/if}
                    {:else if field.input_type === 'model_select'}
                      <select
                        aria-label={field.label}
                        value={String(draftSettings[field.name] ?? '')}
                        onchange={(event) => updateDraft(field.name, event.currentTarget.value, field.input_type)}
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40"
                      >
                        {#if !field.required || field.name === 'ollama_judge_model' || field.name === 'ocr_vision_model'}
                          <option value="">{emptyModelOptionLabel(field.name)}</option>
                        {/if}
                        {#each modelOptionsFor(String(draftSettings[field.name] ?? '')) as name}
                          <option value={name}>{name}</option>
                        {/each}
                      </select>
                    {:else if field.input_type === 'ocr_mode_select'}
                      <select
                        aria-label={field.label}
                        value={String(draftSettings[field.name] ?? 'off')}
                        onchange={(event) => updateDraft(field.name, event.currentTarget.value, field.input_type)}
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40"
                      >
                        {#each ocrModeOptions as option}
                          <option value={option.value}>{option.label}</option>
                        {/each}
                      </select>
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
