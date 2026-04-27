<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import {
    approveCorrespondent,
    approveDoctype,
    approveTag,
    loadTags,
    rejectCorrespondent,
    rejectDoctype,
    rejectTag,
    unblacklistCorrespondent,
    unblacklistDoctype,
    unblacklistTag
  } from '$lib/api';
  import type { ApprovalBlacklistEntity, ApprovalEntity, ApprovalSnapshot, TagsPayload } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  let snapshot = $state<TagsPayload>(undefined as unknown as TagsPayload);
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let busyKey = $state('');
  let initialized = $state(false);

  $effect(() => {
    if (!initialized) {
      snapshot = data.tags;
      initialized = true;
    }
  });

  function pendingCount(items: ApprovalEntity[]) {
    return items.filter((item) => !item.approved).length;
  }

  function formatDate(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric'
    }).format(date);
  }

  async function refresh() {
    snapshot = await loadTags(fetch);
  }

  async function runAction(
    key: string,
    action: () => Promise<{ ok: boolean; message: string }>
  ) {
    busyKey = key;
    feedback = null;
    try {
      const response = await action();
      feedback = { type: response.ok ? 'success' : 'error', message: response.message };
      await refresh();
    } catch (error) {
      feedback = {
        type: 'error',
        message: error instanceof Error ? error.message : 'Aktion fehlgeschlagen.'
      };
    } finally {
      busyKey = '';
    }
  }

  const sections: Array<{
    key: 'tags' | 'correspondents' | 'doctypes';
    title: string;
    subtitle: string;
    accent: 'blue' | 'emerald' | 'purple';
    approve: (name: string) => Promise<{ ok: boolean; message: string }>;
    reject: (name: string) => Promise<{ ok: boolean; message: string }>;
    unblacklist: (name: string) => Promise<{ ok: boolean; message: string }>;
  }> = [
    {
      key: 'tags',
      title: 'Tags',
      subtitle: 'Neue Tags aus Vorschlägen freigeben oder blockieren.',
      accent: 'blue',
      approve: approveTag,
      reject: rejectTag,
      unblacklist: unblacklistTag
    },
    {
      key: 'correspondents',
      title: 'Korrespondenten',
      subtitle: 'Neue Korrespondenten für Paperless übernehmen.',
      accent: 'emerald',
      approve: approveCorrespondent,
      reject: rejectCorrespondent,
      unblacklist: unblacklistCorrespondent
    },
    {
      key: 'doctypes',
      title: 'Dokumenttypen',
      subtitle: 'Neue Typen prüfen und rückwirkend verfügbar machen.',
      accent: 'purple',
      approve: approveDoctype,
      reject: rejectDoctype,
      unblacklist: unblacklistDoctype
    }
  ];
</script>

<AppShell title="Freigaben" subtitle="Tags, Korrespondenten und Dokumenttypen an einer Stelle prüfen und übernehmen.">
  {#snippet children()}
    {#if initialized}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
      {#each sections as section}
        {@const state = snapshot[section.key] as ApprovalSnapshot}
        <StatCard title={`${section.title} offen`} value={pendingCount(state.whitelist)} hint="Warten auf Freigabe" accent={section.accent} />
      {/each}
    </div>

    {#if feedback}
      <div class={`mt-6 rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
        {feedback.message}
      </div>
    {/if}

    <div class="mt-6 space-y-6">
      {#each sections as section}
        {@const state = snapshot[section.key] as ApprovalSnapshot}
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
          <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pending approvals</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">{section.title}</h2>
                <p class="mt-2 text-sm text-slate-400">{section.subtitle}</p>
              </div>
              <Badge color="gray">{pendingCount(state.whitelist)} offen</Badge>
            </div>

            <div class="mt-6 space-y-3">
              {#each state.whitelist.filter((item) => !item.approved) as item}
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                  <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                      <div class="font-medium text-white">{item.name}</div>
                      <div class="mt-1 text-xs text-slate-500">
                        gesehen: {item.times_seen} · zuerst: {formatDate(item.first_seen)}
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <Button size="sm" color="green" onclick={() => void runAction(`${section.key}:approve:${item.name}`, () => section.approve(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `${section.key}:approve:${item.name}` ? 'Freigabe …' : 'Freigeben'}
                      </Button>
                      <Button size="sm" color="red" onclick={() => void runAction(`${section.key}:reject:${item.name}`, () => section.reject(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `${section.key}:reject:${item.name}` ? 'Blockiert …' : 'Blockieren'}
                      </Button>
                    </div>
                  </div>
                </div>
              {:else}
                <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-sm text-emerald-100">
                  Keine offenen {section.title.toLowerCase()}-Vorschläge.
                </div>
              {/each}
            </div>
          </Card>

          <div class="space-y-6">
            <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Approved</p>
                  <h3 class="mt-2 text-xl font-semibold text-white">Bereits freigegeben</h3>
                </div>
                <Badge color="green">{state.whitelist.filter((item) => item.approved).length}</Badge>
              </div>

              <div class="mt-4 space-y-3">
                {#each state.whitelist.filter((item) => item.approved).slice(0, 6) as item}
                  <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-3">
                    <div class="font-medium text-white">{item.name}</div>
                    <div class="mt-1 text-xs text-slate-500">Paperless-ID: {item.paperless_id ?? '—'}</div>
                  </div>
                {:else}
                  <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">Noch nichts freigegeben.</div>
                {/each}
              </div>
            </Card>

            <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Blacklist</p>
                  <h3 class="mt-2 text-xl font-semibold text-white">Blockiert</h3>
                </div>
                <Badge color="gray">{state.blacklist.length}</Badge>
              </div>

              <div class="mt-4 space-y-3">
                {#each state.blacklist.slice(0, 6) as item}
                  <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-3">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <div class="font-medium text-white">{item.name}</div>
                        <div class="mt-1 text-xs text-slate-500">abgelehnt: {formatDate(item.rejected_at)}</div>
                      </div>
                      <Button size="xs" color="alternative" onclick={() => void runAction(`${section.key}:restore:${item.name}`, () => section.unblacklist(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `${section.key}:restore:${item.name}` ? '…' : 'Entfernen'}
                      </Button>
                    </div>
                  </div>
                {:else}
                  <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">Keine blockierten Einträge.</div>
                {/each}
              </div>
            </Card>
          </div>
        </div>
      {/each}
    </div>
    {/if}
  {/snippet}
</AppShell>
