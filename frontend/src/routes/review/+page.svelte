<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import ConfidenceBadge from '$lib/components/ConfidenceBadge.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import LoadingSkeleton from '$lib/components/LoadingSkeleton.svelte';
  import SuggestionDiff from '$lib/components/SuggestionDiff.svelte';
  import {
    acceptReviewSuggestion,
    bulkAcceptReviewSuggestions,
    bulkRejectReviewSuggestions,
    apiResourceUrl,
    loadReviewDetail,
    loadReviewQueue,
    rejectReviewSuggestion,
    saveReviewSuggestion
  } from '$lib/api';
  import type { ReviewDetailPayload, ReviewEntityOption, ReviewQueueItem, ReviewQueuePayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  const initialReview = () => data.review;
  const initialUrlState = () => data.urlState;

  let initialized = $state(false);
  let syncingUrl = $state(false);
  let items = $state<ReviewQueueItem[]>([...initialReview().items]);
  let queueMeta = $state<ReviewQueuePayload>(initialReview());
  let selectedId = $state<number | null>(initialReview().items[0]?.id ?? null);
  let loadedDetailId = $state<number | null>(null);
  let detail = $state<ReviewDetailPayload | null>(null);
  let loadingDetail = $state(false);
  let detailError = $state('');
  let mutationState = $state<'save' | 'accept' | 'reject' | 'bulk-accept' | 'bulk-reject' | null>(null);
  let feedback = $state<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);

  let search = $state('');
  let page = $state(initialUrlState().page || initialReview().page);
  let perPage = $state(initialUrlState().perPage || initialReview().per_page);
  let minConfidence = $state(initialUrlState().minConfidence);
  let maxConfidence = $state(initialUrlState().maxConfidence);
  let sort = $state<'created_desc' | 'confidence_asc' | 'confidence_desc'>((initialUrlState().sort as 'created_desc' | 'confidence_asc' | 'confidence_desc') || 'created_desc');
  let judgeVerdict = $state(initialUrlState().judgeVerdict);
  let correspondentId = $state(initialUrlState().correspondentId);

  let formTitle = $state('');
  let formDate = $state('');
  let formCorrespondentId = $state('');
  let formDoctypeId = $state('');
  let formStoragePathId = $state('');
  let selectedTagIds = $state<number[]>([]);

  let correspondentQuery = $state('');
  let doctypeQuery = $state('');
  let storagePathQuery = $state('');
  let tagSearch = $state('');

  let previewVisible = $state(true);
  let shortcutsOpen = $state(false);
  let shortcutHintDismissed = $state(false);

  function queueParams() {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', String(perPage));
    params.set('min_conf', String(minConfidence));
    params.set('max_conf', String(maxConfidence));
    params.set('sort', sort);
    if (judgeVerdict) params.set('judge_verdict', judgeVerdict);
    if (correspondentId) params.set('correspondent_id', correspondentId);
    return params;
  }

  let filteredItems = $derived.by(() => {
    const needle = search.trim().toLowerCase();
    return items.filter((item) => {
      const haystack = [
        item.proposed_title,
        item.proposed_correspondent_name,
        item.proposed_doctype_name,
        item.proposed_storage_path_name,
        String(item.document_id),
        String(item.id)
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      return !needle || haystack.includes(needle);
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

  let selectedTagObjects = $derived(
    detail?.options.tags.filter((tag) => selectedTagIds.includes(tag.id)) ?? []
  );

  let filteredTagOptions = $derived.by(() => {
    const options = detail?.options.tags ?? [];
    const needle = tagSearch.trim().toLowerCase();
    return options
      .filter((tag) => !selectedTagIds.includes(tag.id))
      .filter((tag) => !needle || tag.name.toLowerCase().includes(needle))
      .slice(0, 18);
  });

  function selectedEntityName(options: ReviewEntityOption[], value: string) {
    if (!value) return '';
    return options.find((option) => String(option.id) === value)?.name ?? '';
  }

  function filteredEntityOptions(options: ReviewEntityOption[], query: string, currentValue: string) {
    const currentName = selectedEntityName(options, currentValue);
    const needle = query.trim().toLowerCase();
    return options
      .filter((option) => !needle || option.name.toLowerCase().includes(needle) || option.name === currentName)
      .slice(0, 12);
  }

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

  function fieldWrap(changed: boolean) {
    return changed ? 'rounded-2xl border border-amber-500/30 bg-amber-500/10 p-3' : '';
  }

  function originalValueTone(changed: boolean) {
    return changed ? 'text-slate-500 line-through' : 'text-slate-100';
  }

  function syncForm(payload: ReviewDetailPayload) {
    formTitle = payload.proposed.title;
    formDate = payload.proposed.date ?? '';
    formCorrespondentId = payload.proposed.correspondent_id !== null ? String(payload.proposed.correspondent_id) : '';
    formDoctypeId = payload.proposed.doctype_id !== null ? String(payload.proposed.doctype_id) : '';
    formStoragePathId = payload.proposed.storage_path_id !== null ? String(payload.proposed.storage_path_id) : '';
    selectedTagIds = payload.proposed.tags.flatMap((tag) => (tag.id !== null ? [tag.id] : []));
    correspondentQuery = payload.proposed.correspondent_name ?? payload.proposed.suggested_correspondent_name ?? '';
    doctypeQuery = payload.proposed.doctype_name ?? payload.proposed.suggested_doctype_name ?? '';
    storagePathQuery = payload.proposed.storage_path_name ?? payload.proposed.suggested_storage_path_name ?? '';
    tagSearch = '';
  }

  async function refreshQueue(preferredId: number | null = null) {
    const next = await loadReviewQueue(fetch, queueParams());
    queueMeta = next;
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

  function nextSelectionAfterMutation(currentId: number | null) {
    if (currentId === null) return filteredItems[0]?.id ?? null;
    const index = filteredItems.findIndex((item) => item.id === currentId);
    return filteredItems[index + 1]?.id ?? filteredItems[index - 1]?.id ?? null;
  }

  async function runAction(action: 'save' | 'accept' | 'reject') {
    if (!selectedId) return;
    const activeId = selectedId;
    const preferredNext = nextSelectionAfterMutation(activeId);
    mutationState = action;
    feedback = null;

    try {
      const response =
        action === 'save'
          ? await saveReviewSuggestion(activeId, buildPayload())
          : action === 'accept'
            ? await acceptReviewSuggestion(activeId, buildPayload())
            : await rejectReviewSuggestion(activeId);

      feedback = { type: response.ok ? 'success' : 'error', message: response.message };

      if (action === 'save') {
        await Promise.all([refreshQueue(activeId), fetchDetail(activeId)]);
      } else {
        await refreshQueue(preferredNext);
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
      await refreshQueue(null);
      detail = null;
      loadedDetailId = null;
    } catch (error) {
      feedback = {
        type: 'error',
        message: error instanceof Error ? error.message : 'Bulk-Aktion fehlgeschlagen.'
      };
    } finally {
      mutationState = null;
    }
  }

  async function applyQueueFilters(resetPage = true) {
    if (minConfidence > maxConfidence) {
      const swap = minConfidence;
      minConfidence = maxConfidence;
      maxConfidence = swap;
    }
    if (resetPage) page = 1;
    syncingUrl = true;
    try {
      await refreshQueue(selectedId);
    } finally {
      syncingUrl = false;
    }
  }

  function moveSelection(delta: number) {
    if (filteredItems.length === 0) return;
    const index = filteredItems.findIndex((item) => item.id === selectedId);
    const nextIndex = index === -1 ? 0 : Math.max(0, Math.min(filteredItems.length - 1, index + delta));
    selectedId = filteredItems[nextIndex]?.id ?? selectedId;
  }

  function addTag(tagId: number) {
    if (!selectedTagIds.includes(tagId)) {
      selectedTagIds = [...selectedTagIds, tagId];
    }
    tagSearch = '';
  }

  function removeTag(tagId: number) {
    selectedTagIds = selectedTagIds.filter((id) => id !== tagId);
  }

  function isTypingTarget(target: EventTarget | null) {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
  }

  $effect(() => {
    if (!initialized) {
      if (typeof window !== 'undefined') {
        previewVisible = window.localStorage.getItem('archibot-review-preview') !== 'false';
        shortcutHintDismissed = window.localStorage.getItem('archibot-review-shortcut-hint-dismissed') === 'true';
      }
      initialized = true;
      return;
    }

    if (filteredItems.length === 0) {
      selectedId = items[0]?.id ?? null;
      if (!selectedId) {
        detail = null;
        loadedDetailId = null;
      }
      return;
    }

    if (selectedId === null || !items.some((item) => item.id === selectedId)) {
      selectedId = filteredItems[0]?.id ?? items[0]?.id ?? null;
    }
  });

  $effect(() => {
    const id = selectedId;
    if (id !== null && loadedDetailId !== id) {
      void fetchDetail(id);
    }
  });

  $effect(() => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem('archibot-review-preview', previewVisible ? 'true' : 'false');
  });

  $effect(() => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem('archibot-review-shortcut-hint-dismissed', shortcutHintDismissed ? 'true' : 'false');
  });

  $effect(() => {
    if (typeof window === 'undefined' || !initialized || syncingUrl) return;
    const params = queueParams();
    const next = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState({}, '', next);
  });

  $effect(() => {
    if (typeof window === 'undefined') return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === '?') {
        event.preventDefault();
        shortcutsOpen = true;
        return;
      }

      if (shortcutsOpen) {
        if (event.key === 'Escape') shortcutsOpen = false;
        return;
      }

      if (isTypingTarget(event.target)) return;

      if (event.key === 'j') {
        event.preventDefault();
        moveSelection(1);
      } else if (event.key === 'k') {
        event.preventDefault();
        moveSelection(-1);
      } else if (event.key === 'Enter' && selectedId !== null) {
        event.preventDefault();
        if (loadedDetailId !== selectedId) void fetchDetail(selectedId);
      } else if (event.key === 'a' && detail) {
        event.preventDefault();
        void runAction('accept');
      } else if (event.key === 'r' && detail) {
        event.preventDefault();
        void runAction('reject');
      } else if (event.key === 'e' && detail) {
        event.preventDefault();
        void runAction('save');
      } else if (event.key === 'Escape' && detail) {
        event.preventDefault();
        detail = null;
        loadedDetailId = null;
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  });
</script>

<AppShell
  title="Review Queue"
  subtitle="Dokumentvorschläge prüfen, filtern und direkt übernehmen — mit Preview, Diff-Hervorhebung, Tastenkürzeln und skalierbarer Queue-Navigation."
  navBadges={{ review: queueMeta?.total ?? data.review.total }}
>
  {#snippet children()}
    {#if !shortcutHintDismissed}
      <div class="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-sky-500/20 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
        <span>Tipp: Drücke <strong>?</strong> für Tastenkürzel.</span>
        <button type="button" class="rounded-lg border border-sky-400/20 px-3 py-1 text-xs" onclick={() => (shortcutHintDismissed = true)}>
          Ausblenden
        </button>
      </div>
    {/if}

    {#if items.length === 0}
      <EmptyState icon="✅" title="Keine offenen Vorschläge" description="Sobald neue Dokumente klassifiziert wurden, erscheinen sie hier automatisch. Starte bei Bedarf Polling oder Reindex in den Einstellungen." actionHref="/app/settings" actionLabel="Polling oder Reindex starten" />
    {:else}
      {#if feedback}
        <div class={`mb-4 rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : feedback.type === 'info' ? 'border-sky-500/20 bg-sky-500/10 text-sky-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
          {feedback.message}
        </div>
      {/if}

      <div class="grid gap-6 xl:grid-cols-[minmax(22rem,0.78fr)_minmax(0,1.72fr)]">
        <div class="space-y-6 xl:border-r xl:border-slate-800/80 xl:pr-6">
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Review Queue</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">{queueMeta.total} insgesamt, Seite {queueMeta.page}/{queueMeta.total_pages}</h2>
                <p class="mt-2 text-sm text-slate-400">Filtere nach Konfidenz, Korrespondent, Judge-Verdikt und Sortierung. Suche wirkt zusätzlich clientseitig auf die aktuelle Seite.</p>
              </div>
              <a href="/review" class="inline-flex"><Button color="dark" class="rounded-xl border border-slate-700">Legacy fallback</Button></a>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
              <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4">
                <p class="text-xs uppercase tracking-wide text-emerald-200/70">Hohe Sicherheit</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-50">{queueStats.high}</p>
              </div>
              <div class="rounded-2xl border border-sky-500/20 bg-sky-500/10 p-4">
                <p class="text-xs uppercase tracking-wide text-sky-200/70">Judge korrigiert</p>
                <p class="mt-2 text-2xl font-semibold text-sky-50">{queueStats.judgeCorrected}</p>
              </div>
              <div class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-200/70">Pfad offen</p>
                <p class="mt-2 text-2xl font-semibold text-amber-50">{queueStats.unresolvedPaths}</p>
              </div>
            </div>

            <details class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/60 p-4" open>
              <summary class="cursor-pointer text-sm font-medium text-slate-200">Filter & Sortierung</summary>
              <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="block xl:col-span-3">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Suche auf aktueller Seite</span>
                  <input bind:value={search} type="search" placeholder="Dokument, Vorschlag, Typ …" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-500/40" />
                </label>

                <label class="block">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Min. Konfidenz ({minConfidence}%)</span>
                  <input bind:value={minConfidence} type="range" min="0" max="100" class="w-full accent-emerald-400" />
                </label>
                <label class="block">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Max. Konfidenz ({maxConfidence}%)</span>
                  <input bind:value={maxConfidence} type="range" min="0" max="100" class="w-full accent-sky-400" />
                </label>
                <label class="block">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Sortierung</span>
                  <select bind:value={sort} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                    <option value="created_desc">Neueste zuerst</option>
                    <option value="confidence_desc">Konfidenz absteigend</option>
                    <option value="confidence_asc">Konfidenz aufsteigend</option>
                  </select>
                </label>
                <label class="block">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Judge-Verdikt</span>
                  <select bind:value={judgeVerdict} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                    <option value="">Alle</option>
                    <option value="agree">agree</option>
                    <option value="corrected">corrected</option>
                    <option value="skipped">skipped</option>
                    <option value="error">error</option>
                  </select>
                </label>
                <label class="block">
                  <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Korrespondent</span>
                  <select bind:value={correspondentId} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40">
                    <option value="">Alle</option>
                    {#each queueMeta.filters?.correspondents ?? [] as option}
                      <option value={String(option.id)}>{option.name}</option>
                    {/each}
                  </select>
                </label>
              </div>

              <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-100" onclick={() => void applyQueueFilters(true)}>Filter anwenden</button>
                <button
                  type="button"
                  class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200"
                  onclick={() => {
                    minConfidence = 0;
                    maxConfidence = 100;
                    sort = 'created_desc';
                    judgeVerdict = '';
                    correspondentId = '';
                    search = '';
                    void applyQueueFilters(true);
                  }}
                >
                  Zurücksetzen
                </button>
              </div>
            </details>

            <div class="mt-6 flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Bulk-Aktionen</p>
                <p class="mt-1 text-sm text-slate-300">Wirken auf alle {filteredItems.length} Vorschläge im aktuellen Seiten- und Suchfilter.</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick={() => void runFilteredBulkAction('bulk-accept')} disabled={mutationState !== null || filteredItems.length === 0} class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-100 transition hover:bg-emerald-500/20 disabled:cursor-not-allowed disabled:opacity-50">{mutationState === 'bulk-accept' ? 'Übernimmt …' : 'Gefilterte annehmen'}</button>
                <button type="button" onclick={() => void runFilteredBulkAction('bulk-reject')} disabled={mutationState !== null || filteredItems.length === 0} class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm font-medium text-rose-100 transition hover:bg-rose-500/20 disabled:cursor-not-allowed disabled:opacity-50">{mutationState === 'bulk-reject' ? 'Verwirft …' : 'Gefilterte verwerfen'}</button>
              </div>
            </div>
          </Card>

          <div class="space-y-2">
            {#each filteredItems as item}
              <button type="button" onclick={() => (selectedId = item.id)} class={`block w-full rounded-2xl border px-3.5 py-3 text-left transition ${selectedId === item.id ? 'border-emerald-500/45 bg-emerald-500/10 shadow-md shadow-emerald-950/20 ring-1 ring-emerald-400/10' : 'border-slate-800/90 bg-slate-900/55 hover:border-slate-700 hover:bg-slate-900/85'}`}>
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span class="text-xs font-semibold uppercase tracking-wide text-slate-300">Dokument #{item.document_id}</span>
                      <span class="text-[11px] text-slate-600">#{item.id}</span>
                      {#if item.judge_verdict}
                        <span class={`text-[11px] font-medium ${judgeTone(item.judge_verdict)}`}>Judge: {item.judge_verdict}</span>
                      {/if}
                    </div>
                    <h3 class="mt-1.5 truncate text-sm font-semibold text-white">{item.proposed_title || 'Ohne Titelvorschlag'}</h3>
                    <p class="mt-1 truncate text-xs text-slate-400">{item.proposed_correspondent_name || 'Korrespondent offen'}</p>
                  </div>
                  <ConfidenceBadge confidence={item.confidence} compact />
                </div>
                <div class="mt-2.5 flex min-w-0 flex-wrap gap-1.5 text-[11px] text-slate-300">
                  <span class="max-w-full truncate rounded-full border border-slate-800/90 bg-slate-950/60 px-2 py-1">Typ: {item.proposed_doctype_name || 'Offen'}</span>
                  <span class="max-w-full truncate rounded-full border border-slate-800/90 bg-slate-950/60 px-2 py-1">Pfad: {item.proposed_storage_path_name || 'Nicht gesetzt'}</span>
                </div>
              </button>
            {:else}
              <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-lg shadow-slate-950/20">
                <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-sm text-emerald-100">Keine Vorschläge im aktuellen Filter.</div>
              </Card>
            {/each}
          </div>

          <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300">
            <button type="button" class="rounded-xl border border-slate-700 px-3 py-2 disabled:opacity-40" disabled={queueMeta.page <= 1} onclick={() => { page -= 1; void applyQueueFilters(false); }}>Vorherige</button>
            <span>Seite {queueMeta.page} von {queueMeta.total_pages}</span>
            <button type="button" class="rounded-xl border border-slate-700 px-3 py-2 disabled:opacity-40" disabled={queueMeta.page >= queueMeta.total_pages} onclick={() => { page += 1; void applyQueueFilters(false); }}>Nächste</button>
          </div>
        </div>

        <div class="space-y-6 xl:pl-1">
          {#if loadingDetail}
            <LoadingSkeleton rows={3} />
          {:else if detailError}
            <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20"><div class="rounded-2xl border border-rose-500/20 bg-rose-500/10 p-6 text-sm text-rose-100">{detailError}</div></Card>
          {:else if detail}
            <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
              <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <Badge color="gray">Dokument #{detail.suggestion.document_id}</Badge>
                    <ConfidenceBadge confidence={detail.suggestion.confidence} />
                    {#if detail.suggestion.judge_verdict}<Badge color={detail.suggestion.judge_verdict === 'agree' ? 'green' : detail.suggestion.judge_verdict === 'corrected' ? 'blue' : 'gray'}>Judge: {detail.suggestion.judge_verdict}</Badge>{/if}
                  </div>
                  <h2 class="mt-3 text-2xl font-semibold text-white">Dokument prüfen</h2>
                  <p class="mt-2 text-sm text-slate-400">Preview, Originalwerte und editierbarer Vorschlag nebeneinander — optimiert für den täglichen Review-Workflow.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <button type="button" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200" onclick={() => (previewVisible = !previewVisible)}>{previewVisible ? 'Preview ausblenden' : 'Preview einblenden'}</button>
                  <button type="button" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 disabled:opacity-40" disabled={!detail.suggestion.prev_id} onclick={() => { if (detail?.suggestion.prev_id) selectedId = detail.suggestion.prev_id; }}>← Vorherige</button>
                  <button type="button" class="rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 disabled:opacity-40" disabled={!detail.suggestion.next_id} onclick={() => { if (detail?.suggestion.next_id) selectedId = detail.suggestion.next_id; }}>Nächste →</button>
                </div>
              </div>

              {#if detail.suggestion.reasoning}
                <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Modellbegründung</p>
                  <p class="mt-2 text-sm text-slate-300">{detail.suggestion.reasoning}</p>
                </div>
              {/if}

              <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <SuggestionDiff label="Titel" original={detail.original.title} proposed={formTitle} changed={detail.changed_fields.title} />
                <SuggestionDiff label="Datum" original={detail.original.date} proposed={formDate} changed={detail.changed_fields.date} />
                <SuggestionDiff label="Korrespondent" original={detail.original.correspondent_name} proposed={correspondentQuery || detail.proposed.suggested_correspondent_name} changed={detail.changed_fields.correspondent} />
                <SuggestionDiff label="Dokumenttyp" original={detail.original.doctype_name} proposed={doctypeQuery || detail.proposed.suggested_doctype_name} changed={detail.changed_fields.doctype} />
                <SuggestionDiff label="Speicherpfad" original={detail.original.storage_path_name} proposed={storagePathQuery || detail.proposed.suggested_storage_path_name} changed={detail.changed_fields.storage_path} />
                <SuggestionDiff label="Tags" original={detail.original.tags.map((tag) => tag.name).join(', ')} proposed={selectedTagObjects.map((tag) => tag.name).join(', ')} changed={detail.changed_fields.tags} />
              </div>

              <div class={`mt-6 grid gap-6 ${previewVisible ? 'xl:grid-cols-[minmax(18rem,0.95fr)_minmax(0,1fr)_minmax(0,1fr)] lg:grid-cols-2' : 'xl:grid-cols-2'}`}>
                {#if previewVisible}
                  <div class="rounded-3xl border border-slate-800 bg-slate-950/60 p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                      <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Dokumenten-Preview</p>
                      <a href={apiResourceUrl(detail.suggestion.preview_url)} target="_blank" rel="noreferrer" class="text-xs text-emerald-300">Neu öffnen</a>
                    </div>
                    <iframe title={`Preview document ${detail.suggestion.document_id}`} src={apiResourceUrl(detail.suggestion.preview_url)} class="h-[80vh] w-full rounded-2xl border border-slate-800 bg-white xl:sticky xl:top-24"></iframe>
                  </div>
                {/if}

                <div class="rounded-3xl border border-slate-800 bg-slate-950/60 p-5">
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Bestehende Metadaten</p>
                  <dl class="mt-4 space-y-4 text-sm">
                    <div class={fieldWrap(detail.changed_fields.title)}><dt class="text-slate-500">Titel</dt><dd class={`mt-1 ${originalValueTone(detail.changed_fields.title)}`}>{detail.original.title || '—'}</dd></div>
                    <div class={fieldWrap(detail.changed_fields.date)}><dt class="text-slate-500">Datum</dt><dd class={`mt-1 ${originalValueTone(detail.changed_fields.date)}`}>{detail.original.date || '—'}</dd></div>
                    <div class={fieldWrap(detail.changed_fields.correspondent)}><dt class="text-slate-500">Korrespondent</dt><dd class={`mt-1 ${originalValueTone(detail.changed_fields.correspondent)}`}>{detail.original.correspondent_name || '—'}</dd></div>
                    <div class={fieldWrap(detail.changed_fields.doctype)}><dt class="text-slate-500">Dokumenttyp</dt><dd class={`mt-1 ${originalValueTone(detail.changed_fields.doctype)}`}>{detail.original.doctype_name || '—'}</dd></div>
                    <div class={fieldWrap(detail.changed_fields.storage_path)}><dt class="text-slate-500">Speicherpfad</dt><dd class={`mt-1 ${originalValueTone(detail.changed_fields.storage_path)}`}>{detail.original.storage_path_name || '—'}</dd></div>
                    <div class={fieldWrap(detail.changed_fields.tags)}>
                      <dt class="text-slate-500">Tags</dt>
                      <dd class="mt-2 flex flex-wrap gap-2">
                        {#each detail.original.tags as tag}
                          <span class={`rounded-full border px-2.5 py-1 text-xs ${detail.changed_fields.tags ? 'border-slate-700 bg-slate-900 text-slate-500 line-through' : 'border-slate-700 bg-slate-900 text-slate-300'}`}>{tag.name}</span>
                        {:else}
                          <span class="text-slate-400">—</span>
                        {/each}
                      </dd>
                    </div>
                  </dl>
                </div>

                <div class="rounded-3xl border border-emerald-500/20 bg-emerald-500/5 p-5">
                  <p class="text-xs uppercase tracking-[0.2em] text-emerald-300/70">Bearbeitbarer Vorschlag</p>
                  <div class="mt-4 space-y-4">
                    <label class={`block ${fieldWrap(detail.changed_fields.title)}`}><span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Titel</span><input bind:value={formTitle} class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" /></label>
                    <label class={`block ${fieldWrap(detail.changed_fields.date)}`}><span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Datum</span><input bind:value={formDate} type="date" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" /></label>

                    <div class={fieldWrap(detail.changed_fields.correspondent)}>
                      <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Korrespondent</span>
                      <input bind:value={correspondentQuery} type="search" placeholder="Korrespondent suchen …" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                      <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl border border-slate-700 px-3 py-1.5 text-xs text-slate-300" onclick={() => { formCorrespondentId = ''; correspondentQuery = ''; }}>Kein Eintrag</button>
                        {#each filteredEntityOptions(detail.options.correspondents, correspondentQuery, formCorrespondentId) as option}
                          <button type="button" class={`rounded-xl border px-3 py-1.5 text-xs ${String(option.id) === formCorrespondentId ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' : 'border-slate-700 text-slate-300'}`} onclick={() => { formCorrespondentId = String(option.id); correspondentQuery = option.name; }}>{option.name}</button>
                        {/each}
                      </div>
                      {#if detail.proposed.suggested_correspondent_name && !formCorrespondentId}<p class="mt-2 text-xs text-amber-300">Neuer Vorschlag: {detail.proposed.suggested_correspondent_name}</p>{/if}
                    </div>

                    <div class={fieldWrap(detail.changed_fields.doctype)}>
                      <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Dokumenttyp</span>
                      <input bind:value={doctypeQuery} type="search" placeholder="Dokumenttyp suchen …" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                      <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl border border-slate-700 px-3 py-1.5 text-xs text-slate-300" onclick={() => { formDoctypeId = ''; doctypeQuery = ''; }}>Kein Eintrag</button>
                        {#each filteredEntityOptions(detail.options.doctypes, doctypeQuery, formDoctypeId) as option}
                          <button type="button" class={`rounded-xl border px-3 py-1.5 text-xs ${String(option.id) === formDoctypeId ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' : 'border-slate-700 text-slate-300'}`} onclick={() => { formDoctypeId = String(option.id); doctypeQuery = option.name; }}>{option.name}</button>
                        {/each}
                      </div>
                      {#if detail.proposed.suggested_doctype_name && !formDoctypeId}<p class="mt-2 text-xs text-amber-300">Neuer Vorschlag: {detail.proposed.suggested_doctype_name}</p>{/if}
                    </div>

                    <div class={fieldWrap(detail.changed_fields.storage_path)}>
                      <span class="mb-2 block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Speicherpfad</span>
                      <input bind:value={storagePathQuery} type="search" placeholder="Pfad suchen …" class="w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                      <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl border border-slate-700 px-3 py-1.5 text-xs text-slate-300" onclick={() => { formStoragePathId = ''; storagePathQuery = ''; }}>Kein Eintrag</button>
                        {#each filteredEntityOptions(detail.options.storage_paths, storagePathQuery, formStoragePathId) as option}
                          <button type="button" class={`rounded-xl border px-3 py-1.5 text-xs ${String(option.id) === formStoragePathId ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' : 'border-slate-700 text-slate-300'}`} onclick={() => { formStoragePathId = String(option.id); storagePathQuery = option.name; }}>{option.name}</button>
                        {/each}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class={`mt-6 rounded-3xl border border-slate-800 bg-slate-950/60 p-5 ${fieldWrap(detail.changed_fields.tags)}`}>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                  <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Tags</p>
                    <p class="mt-1 text-sm text-slate-400">Suche nach Tags, füge sie per Combobox-artiger Auswahl hinzu und entferne sie wieder als Chips.</p>
                  </div>
                  {#if unresolvedProposedTags.length > 0}
                    <div class="flex flex-wrap gap-2">{#each unresolvedProposedTags as tagName}<span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-100">Neuer Tag: {tagName}</span>{/each}</div>
                  {/if}
                </div>
                <div class="mt-4 flex flex-wrap gap-2">{#each selectedTagObjects as tag}<button type="button" class="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs text-emerald-100" onclick={() => removeTag(tag.id)}>{tag.name} ×</button>{:else}<span class="text-sm text-slate-500">Keine Tags ausgewählt.</span>{/each}</div>
                <input bind:value={tagSearch} type="search" placeholder="Tags suchen …" class="mt-4 w-full rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-500/40" />
                <div class="mt-3 flex max-h-52 flex-wrap gap-2 overflow-y-auto rounded-2xl border border-slate-800 bg-slate-950/70 p-3">{#each filteredTagOptions as option}<button type="button" class="rounded-xl border border-slate-700 px-3 py-1.5 text-xs text-slate-200 transition hover:border-emerald-500/30 hover:bg-slate-900" onclick={() => addTag(option.id)}>{option.name}</button>{:else}<span class="text-sm text-slate-500">Keine weiteren Tags passend zur Suche.</span>{/each}</div>
              </div>

              {#if detail.context_docs.length > 0 || detail.original_proposal}
                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                  {#if detail.original_proposal}
                    <div class="rounded-3xl border border-sky-500/20 bg-sky-500/10 p-5"><p class="text-xs uppercase tracking-[0.2em] text-sky-200/70">Erster Modellstand</p><pre class="mt-3 overflow-x-auto whitespace-pre-wrap text-xs text-sky-50/90">{JSON.stringify(detail.original_proposal, null, 2)}</pre></div>
                  {/if}
                  {#if detail.context_docs.length > 0}
                    <div class="rounded-3xl border border-slate-800 bg-slate-950/60 p-5"><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Kontextdokumente</p><div class="mt-3 space-y-2">{#each detail.context_docs as doc}<div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3 text-xs text-slate-300"><pre class="overflow-x-auto whitespace-pre-wrap">{JSON.stringify(doc, null, 2)}</pre></div>{/each}</div></div>
                  {/if}
                </div>
              {/if}

              <div class="mt-6 flex flex-col gap-3 border-t border-slate-800/80 pt-5 sm:flex-row">
                <button type="button" onclick={() => void runAction('save')} disabled={mutationState !== null} class="inline-flex flex-1 items-center justify-center rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm font-medium text-slate-100 transition hover:border-slate-600 hover:bg-slate-900 disabled:cursor-not-allowed disabled:opacity-50">{mutationState === 'save' ? 'Speichert …' : 'Änderungen speichern'}</button>
                <button type="button" onclick={() => void runAction('accept')} disabled={mutationState !== null} class="inline-flex flex-1 items-center justify-center rounded-2xl border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm font-medium text-emerald-50 transition hover:bg-emerald-500/25 disabled:cursor-not-allowed disabled:opacity-50">{mutationState === 'accept' ? 'Übernimmt …' : 'Übernehmen & committen'}</button>
                <button type="button" onclick={() => void runAction('reject')} disabled={mutationState !== null} class="inline-flex flex-1 items-center justify-center rounded-2xl border border-rose-500/30 bg-rose-500/15 px-4 py-3 text-sm font-medium text-rose-50 transition hover:bg-rose-500/25 disabled:cursor-not-allowed disabled:opacity-50">{mutationState === 'reject' ? 'Verwirft …' : 'Reject'}</button>
              </div>
            </Card>
          {:else}
            <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20"><div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-6 text-sm text-slate-300">Wähle links einen Vorschlag aus. Mit <strong>j</strong>/<strong>k</strong> springst du durch die Queue, <strong>Enter</strong> lädt das Detail, <strong>a</strong>/<strong>r</strong>/<strong>e</strong> führen Aktionen aus.</div></Card>
          {/if}
        </div>
      </div>
    {/if}

    {#if shortcutsOpen}
      <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-xl rounded-3xl border border-slate-700 bg-slate-900 p-6 shadow-2xl shadow-slate-950/40">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Tastenkürzel</p>
              <h3 class="mt-2 text-2xl font-semibold text-white">Review-Shortcuts</h3>
            </div>
            <button type="button" class="rounded-xl border border-slate-700 px-3 py-2 text-sm text-slate-200" onclick={() => (shortcutsOpen = false)}>Schließen</button>
          </div>
          <div class="mt-6 grid gap-4 sm:grid-cols-2 text-sm text-slate-300">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4"><p class="font-medium text-white">Queue</p><ul class="mt-2 space-y-1"><li><code>j</code> nächste Zeile</li><li><code>k</code> vorherige Zeile</li><li><code>Enter</code> Detail laden</li></ul></div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4"><p class="font-medium text-white">Detail</p><ul class="mt-2 space-y-1"><li><code>a</code> Accept</li><li><code>r</code> Reject</li><li><code>e</code> Save</li><li><code>Esc</code> Detail schließen</li></ul></div>
          </div>
        </div>
      </div>
    {/if}
  {/snippet}
</AppShell>
