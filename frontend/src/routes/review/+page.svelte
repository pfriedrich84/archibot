<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import {
    acceptReviewSuggestion,
    bulkAcceptReviewSuggestions,
    bulkRejectReviewSuggestions,
    loadReviewDetail,
    loadReviewQueue,
    rejectReviewSuggestion,
    saveReviewSuggestion
  } from '$lib/api';
  import type { ReviewDetailPayload, ReviewQueueItem } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  let initialized = $state(false);
  let items = $state<ReviewQueueItem[]>([]);
  let selectedId = $state<number | null>(null);
  let loadedDetailId = $state<number | null>(null);
  let detail = $state<ReviewDetailPayload | null>(null);
  let loadingDetail = $state(false);
  let detailError = $state('');
  let mutationState = $state<'save' | 'accept' | 'reject' | 'bulk-accept' | 'bulk-reject' | null>(null);
  let feedback = $state<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);

  let search = $state('');
  let confidenceFilter = $state<'all' | 'high' | 'medium' | 'low'>('all');

  let formTitle = $state('');
  let formDate = $state('');
  let formCorrespondentId = $state('');
  let formDoctypeId = $state('');
  let formStoragePathId = $state('');
  let selectedTagIds = $state<number[]>([]);

  let filteredItems = $derived.by(() => {
    const needle = search.trim().toLowerCase();

    return items.filter((item) => {
      const haystack = [
        item.proposed_title,
        item.proposed_correspondent_name,
        item.proposed_doctype_name,
        String(item.document_id),
        String(item.id)
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

      const confidence = item.confidence ?? -1;
      const confidenceMatch =
        confidenceFilter === 'all' ||
        (confidenceFilter === 'high' && confidence >= 80) ||
        (confidenceFilter === 'medium' && confidence >= 50 && confidence < 80) ||
        (confidenceFilter === 'low' && confidence >= 0 && confidence < 50);

      return confidenceMatch && (!needle || haystack.includes(needle));
    });
  });

  let queueStats = $derived({
    high: items.filter((item) => (item.confidence ?? 0) >= 80).length,
    judgeCorrected: items.filter((item) => item.judge_verdict === 'corrected').length,
    unresolvedPaths: items.filter((item) => !item.proposed_storage_path_name).length
  });

  let unresolvedProposedTags = $derived(
    detail?.proposed.tags.filter((tag) => tag.id === null).map((tag) => tag.name) ?? []
  );

  function confidenceTone(confidence: number | null) {
    if (confidence === null) return 'border-slate-700 bg-slate-800 text-slate-300';
    if (confidence >= 80) return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100';
    if (confidence >= 50) return 'border-amber-500/30 bg-amber-500/10 text-amber-100';
    return 'border-rose-500/30 bg-rose-500/10 text-rose-100';
  }

  function judgeTone(verdict: string | null) {
    if (verdict === 'agree') return 'text-emerald-300';
    if (verdict === 'corrected') return 'text-sky-300';
    if (verdict === 'error') return 'text-rose-300';
    return 'text-slate-400';
  }

  function syncForm(payload: ReviewDetailPayload) {
    formTitle = payload.proposed.title;
    formDate = payload.proposed.date ?? '';
    formCorrespondentId = payload.proposed.correspondent_id !== null ? String(payload.proposed.correspondent_id) : '';
    formDoctypeId = payload.proposed.doctype_id !== null ? String(payload.proposed.doctype_id) : '';
    formStoragePathId = payload.proposed.storage_path_id !== null ? String(payload.proposed.storage_path_id) : '';
    selectedTagIds = payload.proposed.tags.flatMap((tag) => (tag.id !== null ? [tag.id] : []));
  }

  async function refreshQueue(preferredId: number | null = null) {
    const next = await loadReviewQueue(fetch);
    items = next.items;

    if (preferredId !== null && next.items.some((item) => item.id === preferredId)) {
      selectedId = preferredId;
      return;
    }

    if (selectedId !== null && next.items.some((item) => item.id === selectedId)) {
      return;
    }

    selectedId = next.items[0]?.id ?? null;
  }

  async function fetchDetail(id: number) {
    loadingDetail = true;
    detailError = '';
    feedback = null;
    loadedDetailId = id;

    try {
      const payload = await loadReviewDetail(id, fetch);
      if (selectedId !== id) return;
      detail = payload;
      syncForm(payload);
    } catch (error) {
      if (selectedId !== id) return;
      detail = null;
      detailError = error instanceof Error ? error.message : 'Detaildaten konnten nicht geladen werden.';
    } finally {
      if (selectedId === id) {
        loadingDetail = false;
      }
    }
  }

  function buildPayload() {
    return {
      title: formTitle,
      date: formDate,
      correspondent_id: formCorrespondentId || null,
      doctype_id: formDoctypeId || null,
      storage_path_id: formStoragePathId || null,
      tag_ids: selectedTagIds
    };
  }

  async function runAction(action: 'save' | 'accept' | 'reject') {
    if (!selectedId) return;
    mutationState = action;
    feedback = null;

    try {
      const response =
        action === 'save'
          ? await saveReviewSuggestion(selectedId, buildPayload())
          : action === 'accept'
            ? await acceptReviewSuggestion(selectedId, buildPayload())
            : await rejectReviewSuggestion(selectedId);

      feedback = { type: response.ok ? 'success' : 'error', message: response.message };

      if (action === 'save') {
        await Promise.all([refreshQueue(selectedId), fetchDetail(selectedId)]);
      } else {
        const nextPreferred = filteredItems.find((item) => item.id !== selectedId)?.id ?? null;
        await refreshQueue(nextPreferred);
        detail = null;
        loadedDetailId = null;
      }
    } catch (error) {
      feedback = {
        type: 'error',
        message: error instanceof Error ? error.message : 'Aktion fehlgeschlagen.'
      };
    } finally {
      mutationState = null;
    }
  }

  $effect(() => {
    if (!initialized) {
      items = [...data.review.items];
      selectedId = data.review.items[0]?.id ?? null;
      initialized = true;
      return;
    }

    if (filteredItems.length === 0) {
      selectedId = null;
      detail = null;
      loadedDetailId = null;
      return;
    }

    if (selectedId === null || !filteredItems.some((item) => item.id === selectedId)) {
      selectedId = filteredItems[0].id;
    }
  });

  async function runFilteredBulkAction(action: 'bulk-accept' | 'bulk-reject') {
    const suggestionIds = filteredItems.map((item) => item.id);
    if (suggestionIds.length === 0) {
      feedback = { type: 'info', message: 'Keine Vorschläge im aktuellen Filter.' };
      return;
    }

    mutationState = action;
    feedback = null;

    try {
      const response =
        action === 'bulk-accept'
          ? await bulkAcceptReviewSuggestions(suggestionIds)
          : await bulkRejectReviewSuggestions(suggestionIds);

      feedback = { type: response.ok ? 'success' : 'error', message: response.message };
      const nextPreferred = selectedId !== null && !suggestionIds.includes(selectedId) ? selectedId : null;
      await refreshQueue(nextPreferred);
      if (nextPreferred === null) {
        detail = null;
        loadedDetailId = null;
      }
    } catch (error) {
      feedback = {
        type: 'error',
        message: error instanceof Error ? error.message : 'Bulk-Aktion fehlgeschlagen.'
      };
    } finally {
      mutationState = null;
    }
  }

  $effect(() => {
    const id = selectedId;
    if (id !== null && loadedDetailId !== id) {
      void fetchDetail(id);
    }
  });
</script>

<AppShell
  title="Review Queue"
  subtitle="Native Svelte-Review mit Bearbeiten, Speichern, Accept/Reject und schnellerer Operability statt Legacy-Roundtrip."
>
  {#snippet children()}
    <div class="grid gap-6 xl:grid-cols-[minmax(20rem,0.9fr)_minmax(0,1.3fr)]">
      <div class="space-y-6">
        <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Moderation Queue</p>
              <h2 class="mt-2 text-2xl font-semibold text-white">{items.length} aktive Vorschläge</h2>
              <p class="mt-2 text-sm text-slate-400">
                Direkte Bearbeitung ohne Wechsel in die Legacy-Ansicht. Fokus auf schnelle Entscheidungen und sichtbare Unsicherheiten.
              </p>
            </div>
            <a href="/review" class="inline-flex">
              <Button color="dark" class="rounded-xl border border-slate-700">Legacy fallback</Button>
            </a>
          </div>

          <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4">
              <p class="text-xs uppercase tracking-wide text-emerald-200/70">High confidence</p>
              <p class="mt-2 text-2xl font-semibold text-emerald-50">{queueStats.high}</p>
            </div>
            <div class="rounded-2xl border border-sky-500/20 bg-sky-500/10 p-4">
              <p class="text-xs uppercase tracking-wide text-sky-200/70">Judge corrected</p>
              <p class="mt-2 text-2xl font-semibold text-sky-50">{queueStats.judgeCorrected}</p>
            </div>
            <div class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-4">
              <p class="text-xs uppercase tracking-wide text-amber-200/70">Path unresolved</p>
              <p class="mt-2 text-2xl font-semibold text-amber-50">{queueStats.unresolvedPaths}</p>
            </div>
          </div>

          <div class="mt-6 grid gap-3 md:grid-cols-[minmax(0,1fr)_13rem]">
            <label class="block">
              <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Suche</span>
              <input
                bind:value={search}
                type="search"
                placeholder="Dokument, Vorschlag, Typ …"
                class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-500/40"
              />
            </label>
            <label class="block">
              <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Confidence</span>
              <select
                bind:value={confidenceFilter}
                class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40"
              >
                <option value="all">Alle</option>
                <option value="high">80–100</option>
                <option value="medium">50–79</option>
                <option value="low">0–49</option>
              </select>
            </label>
          </div>

          <div class="mt-6 flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Bulk actions</p>
              <p class="mt-1 text-sm text-slate-300">Wirken auf alle {filteredItems.length} Vorschläge im aktuellen Filter.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button
                type="button"
                onclick={() => void runFilteredBulkAction('bulk-accept')}
                disabled={mutationState !== null || filteredItems.length === 0}
                class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-100 transition hover:bg-emerald-500/20 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {mutationState === 'bulk-accept' ? 'Übernimmt …' : 'Bulk accept filtered'}
              </button>
              <button
                type="button"
                onclick={() => void runFilteredBulkAction('bulk-reject')}
                disabled={mutationState !== null || filteredItems.length === 0}
                class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm font-medium text-rose-100 transition hover:bg-rose-500/20 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {mutationState === 'bulk-reject' ? 'Verwirft …' : 'Bulk reject filtered'}
              </button>
            </div>
          </div>
        </Card>

        <div class="space-y-3">
          {#each filteredItems as item}
            <button
              type="button"
              onclick={() => {
                if (selectedId !== item.id) {
                  selectedId = item.id;
                }
              }}
              class={`block w-full rounded-3xl border p-5 text-left transition ${
                selectedId === item.id
                  ? 'border-emerald-500/40 bg-emerald-500/10 shadow-lg shadow-emerald-950/20'
                  : 'border-slate-800 bg-slate-900/75 hover:border-slate-700 hover:bg-slate-900'
              }`}
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-white">#{item.document_id}</span>
                    <span class="text-xs text-slate-500">Suggestion #{item.id}</span>
                    {#if item.document_status}
                      <span class="rounded-full border border-slate-700 bg-slate-950/80 px-2 py-1 text-[11px] uppercase tracking-wide text-slate-300">
                        {item.document_status}
                      </span>
                    {/if}
                  </div>
                  <h3 class="mt-3 text-lg font-semibold text-white">{item.proposed_title || 'Ohne Titelvorschlag'}</h3>
                  <p class="mt-1 text-sm text-slate-400">{item.proposed_correspondent_name || 'Korrespondent offen'}</p>
                </div>
                <span class={`inline-flex rounded-full border px-3 py-1 text-xs font-medium ${confidenceTone(item.confidence)}`}>
                  {item.confidence ?? '—'}{item.confidence !== null ? '%' : ''}
                </span>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full border border-slate-800 bg-slate-950/70 px-2.5 py-1 text-slate-300">
                  {item.proposed_doctype_name || 'Typ offen'}
                </span>
                {#if item.proposed_storage_path_name}
                  <span class="rounded-full border border-slate-800 bg-slate-950/70 px-2.5 py-1 text-slate-400">
                    {item.proposed_storage_path_name}
                  </span>
                {/if}
                {#if item.judge_verdict}
                  <span class={`font-medium ${judgeTone(item.judge_verdict)}`}>Judge: {item.judge_verdict}</span>
                {/if}
              </div>
            </button>
          {:else}
            <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
              <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-sm text-emerald-100">
                Keine Vorschläge im aktuellen Filter.
              </div>
            </Card>
          {/each}
        </div>
      </div>

      <div class="space-y-6">
        {#if feedback}
          <div class={`rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : feedback.type === 'info' ? 'border-sky-500/20 bg-sky-500/10 text-sky-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
            {feedback.message}
          </div>
        {/if}

        {#if loadingDetail}
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="space-y-3">
              <div class="h-5 w-40 animate-pulse rounded bg-slate-800"></div>
              <div class="h-12 animate-pulse rounded-2xl bg-slate-800"></div>
              <div class="h-12 animate-pulse rounded-2xl bg-slate-800"></div>
              <div class="h-48 animate-pulse rounded-3xl bg-slate-800"></div>
            </div>
          </Card>
        {:else if detailError}
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="rounded-2xl border border-rose-500/20 bg-rose-500/10 p-6 text-sm text-rose-100">{detailError}</div>
          </Card>
        {:else if detail}
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <Badge color="gray">Dokument #{detail.suggestion.document_id}</Badge>
                  <Badge color={detail.suggestion.confidence !== null && detail.suggestion.confidence >= 80 ? 'green' : detail.suggestion.confidence !== null && detail.suggestion.confidence >= 50 ? 'yellow' : 'red'}>
                    {detail.suggestion.confidence ?? '—'}{detail.suggestion.confidence !== null ? '%' : ''}
                  </Badge>
                  {#if detail.suggestion.judge_verdict}
                    <Badge color={detail.suggestion.judge_verdict === 'agree' ? 'green' : detail.suggestion.judge_verdict === 'corrected' ? 'blue' : 'gray'}>
                      Judge: {detail.suggestion.judge_verdict}
                    </Badge>
                  {/if}
                </div>
                <h2 class="mt-3 text-2xl font-semibold text-white">Review Inspector</h2>
                <p class="mt-2 text-sm text-slate-400">Feinjustiere Vorschlag, speichere Zwischenschritte oder committe direkt nach Paperless.</p>
              </div>
              <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-3 text-right">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Created</p>
                <p class="mt-1 text-sm text-slate-200">{detail.suggestion.created_at}</p>
              </div>
            </div>

            {#if detail.suggestion.reasoning}
              <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Model reasoning</p>
                <p class="mt-2 text-sm text-slate-300">{detail.suggestion.reasoning}</p>
              </div>
            {/if}

            {#if detail.suggestion.judge_reasoning}
              <div class="mt-4 rounded-2xl border border-sky-500/20 bg-sky-500/10 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-sky-200/70">Judge note</p>
                <p class="mt-2 text-sm text-sky-50/90">{detail.suggestion.judge_reasoning}</p>
              </div>
            {/if}

            <div class="mt-6 grid gap-6 xl:grid-cols-2">
              <div class="rounded-3xl border border-slate-800 bg-slate-950/60 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Original</p>
                <dl class="mt-4 space-y-4 text-sm">
                  <div>
                    <dt class="text-slate-500">Title</dt>
                    <dd class="mt-1 text-slate-100">{detail.original.title || '—'}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-500">Date</dt>
                    <dd class="mt-1 text-slate-100">{detail.original.date || '—'}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-500">Correspondent</dt>
                    <dd class="mt-1 text-slate-100">{detail.original.correspondent_name || '—'}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-500">Document type</dt>
                    <dd class="mt-1 text-slate-100">{detail.original.doctype_name || '—'}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-500">Storage path</dt>
                    <dd class="mt-1 text-slate-100">{detail.original.storage_path_name || '—'}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-500">Tags</dt>
                    <dd class="mt-2 flex flex-wrap gap-2">
                      {#each detail.original.tags as tag}
                        <span class="rounded-full border border-slate-700 bg-slate-900 px-2.5 py-1 text-xs text-slate-300">{tag.name}</span>
                      {:else}
                        <span class="text-slate-400">—</span>
                      {/each}
                    </dd>
                  </div>
                </dl>
              </div>

              <div class="rounded-3xl border border-emerald-500/20 bg-emerald-500/5 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-300/70">Editable proposal</p>
                <div class="mt-4 space-y-4">
                  <label class="block">
                    <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Title</span>
                    <input bind:value={formTitle} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                  </label>

                  <label class="block">
                    <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Date</span>
                    <input bind:value={formDate} type="date" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                  </label>

                  <label class="block">
                    <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Correspondent</span>
                    <select bind:value={formCorrespondentId} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                      <option value="">— None —</option>
                      {#each detail.options.correspondents as option}
                        <option value={String(option.id)}>{option.name}</option>
                      {/each}
                    </select>
                    {#if detail.proposed.suggested_correspondent_name}
                      <p class="mt-2 text-xs text-amber-300">Suggested new: {detail.proposed.suggested_correspondent_name}</p>
                    {/if}
                  </label>

                  <label class="block">
                    <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Document type</span>
                    <select bind:value={formDoctypeId} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                      <option value="">— None —</option>
                      {#each detail.options.doctypes as option}
                        <option value={String(option.id)}>{option.name}</option>
                      {/each}
                    </select>
                    {#if detail.proposed.suggested_doctype_name}
                      <p class="mt-2 text-xs text-amber-300">Suggested new: {detail.proposed.suggested_doctype_name}</p>
                    {/if}
                  </label>

                  <label class="block">
                    <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Storage path</span>
                    <select bind:value={formStoragePathId} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                      <option value="">— None —</option>
                      {#each detail.options.storage_paths as option}
                        <option value={String(option.id)}>{option.name}</option>
                      {/each}
                    </select>
                    {#if detail.proposed.suggested_storage_path_name}
                      <p class="mt-2 text-xs text-amber-300">Suggested new: {detail.proposed.suggested_storage_path_name}</p>
                    {/if}
                  </label>
                </div>
              </div>
            </div>

            <div class="mt-6 rounded-3xl border border-slate-800 bg-slate-950/60 p-5">
              <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Tags</p>
                  <p class="mt-1 text-sm text-slate-400">Bekannte Tags direkt auswählen. Ungelöste neue Tags bleiben als Hinweis sichtbar.</p>
                </div>
                {#if unresolvedProposedTags.length > 0}
                  <div class="flex flex-wrap gap-2">
                    {#each unresolvedProposedTags as tagName}
                      <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-100">New tag: {tagName}</span>
                    {/each}
                  </div>
                {/if}
              </div>

              <div class="mt-4 grid max-h-72 gap-2 overflow-y-auto rounded-2xl border border-slate-800 bg-slate-950/70 p-3 sm:grid-cols-2 xl:grid-cols-3">
                {#each detail.options.tags as option}
                  <label class="flex items-center gap-3 rounded-2xl border border-slate-800 px-3 py-2 text-sm text-slate-200 transition hover:border-slate-700 hover:bg-slate-900/80">
                    <input
                      type="checkbox"
                      checked={selectedTagIds.includes(option.id)}
                      onchange={(event) => {
                        const checked = (event.currentTarget as HTMLInputElement).checked;
                        selectedTagIds = checked
                          ? [...selectedTagIds, option.id]
                          : selectedTagIds.filter((id) => id !== option.id);
                      }}
                      class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-emerald-400"
                    />
                    <span>{option.name}</span>
                  </label>
                {/each}
              </div>
            </div>

            {#if detail.context_docs.length > 0 || detail.original_proposal}
              <div class="mt-6 grid gap-6 xl:grid-cols-2">
                {#if detail.original_proposal}
                  <div class="rounded-3xl border border-sky-500/20 bg-sky-500/10 p-5">
                    <p class="text-xs uppercase tracking-[0.2em] text-sky-200/70">First-pass snapshot</p>
                    <pre class="mt-3 overflow-x-auto whitespace-pre-wrap text-xs text-sky-50/90">{JSON.stringify(detail.original_proposal, null, 2)}</pre>
                  </div>
                {/if}

                {#if detail.context_docs.length > 0}
                  <div class="rounded-3xl border border-slate-800 bg-slate-950/60 p-5">
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Context docs</p>
                    <div class="mt-3 space-y-2">
                      {#each detail.context_docs as doc}
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3 text-xs text-slate-300">
                          <pre class="overflow-x-auto whitespace-pre-wrap">{JSON.stringify(doc, null, 2)}</pre>
                        </div>
                      {/each}
                    </div>
                  </div>
                {/if}
              </div>
            {/if}

            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
              <button
                type="button"
                onclick={() => void runAction('save')}
                disabled={mutationState !== null}
                class="inline-flex flex-1 items-center justify-center rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm font-medium text-slate-100 transition hover:border-slate-600 hover:bg-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {mutationState === 'save' ? 'Speichert …' : 'Save changes'}
              </button>
              <button
                type="button"
                onclick={() => void runAction('accept')}
                disabled={mutationState !== null}
                class="inline-flex flex-1 items-center justify-center rounded-2xl border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm font-medium text-emerald-50 transition hover:bg-emerald-500/25 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {mutationState === 'accept' ? 'Commit läuft …' : 'Accept & commit'}
              </button>
              <button
                type="button"
                onclick={() => void runAction('reject')}
                disabled={mutationState !== null}
                class="inline-flex flex-1 items-center justify-center rounded-2xl border border-rose-500/30 bg-rose-500/15 px-4 py-3 text-sm font-medium text-rose-50 transition hover:bg-rose-500/25 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {mutationState === 'reject' ? 'Verwirft …' : 'Reject'}
              </button>
            </div>
          </Card>
        {:else}
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-6 text-sm text-slate-300">
              Wähle links einen Vorschlag aus, um ihn nativ in der Svelte-Oberfläche zu bearbeiten.
            </div>
          </Card>
        {/if}
      </div>
    </div>
  {/snippet}
</AppShell>
