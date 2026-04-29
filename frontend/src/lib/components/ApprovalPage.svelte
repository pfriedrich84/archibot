<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import { loadTags } from '$lib/api';
  import type { ApprovalEntity, ApprovalSnapshot, TagsPayload } from '$lib/types';

  let {
    title,
    subtitle,
    navKey,
    snapshotKey,
    accent = 'blue',
    approve,
    reject,
    unblacklist,
    initial
  } = $props<{
    title: string;
    subtitle: string;
    navKey: string;
    snapshotKey: 'tags' | 'correspondents' | 'doctypes';
    accent?: 'blue' | 'emerald' | 'purple';
    approve: (name: string) => Promise<{ ok: boolean; message: string }>;
    reject: (name: string) => Promise<{ ok: boolean; message: string }>;
    unblacklist: (name: string) => Promise<{ ok: boolean; message: string }>;
    initial: TagsPayload;
  }>();
  let snapshot = $state<TagsPayload>(undefined as unknown as TagsPayload);
  let feedback = $state<{ type: 'success' | 'error'; message: string } | null>(null);
  let busyKey = $state('');
  let search = $state('');

  let initialized = $state(false);

  $effect(() => {
    if (!initialized) {
      snapshot = initial;
      initialized = true;
    }
  });

  let approvalState = $derived.by((): ApprovalSnapshot => {
    if (snapshotKey === 'correspondents') return snapshot.correspondents;
    if (snapshotKey === 'doctypes') return snapshot.doctypes;
    return snapshot.tags;
  });
  let pending = $derived.by(() => approvalState.whitelist.filter((item: ApprovalEntity) => !item.approved));
  let approved = $derived.by(() => approvalState.whitelist.filter((item: ApprovalEntity) => item.approved));
  let filteredPending = $derived.by(() => filterApprovalItems(pending, search));
  let filteredApproved = $derived.by(() => filterApprovalItems(approved, search));
  let filteredBlacklist = $derived.by(() => filterBlacklistItems(approvalState.blacklist, search));

  function filterApprovalItems(items: ApprovalEntity[], value: string) {
    const needle = value.trim().toLowerCase();
    return needle ? items.filter((item) => item.name.toLowerCase().includes(needle)) : items;
  }

  function filterBlacklistItems(items: import('$lib/types').ApprovalBlacklistEntity[], value: string) {
    const needle = value.trim().toLowerCase();
    return needle ? items.filter((item) => item.name.toLowerCase().includes(needle)) : items;
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

  async function runAction(key: string, action: () => Promise<{ ok: boolean; message: string }>) {
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
</script>

<AppShell {title} {subtitle} navBadges={{ [navKey]: pending.length }}>
  {#snippet children()}
    <div class="mx-auto max-w-7xl space-y-6">
      <div class="grid gap-4 md:grid-cols-3">
        <StatCard title="Offen" value={pending.length} hint="Wartet auf Entscheidung" {accent} />
        <StatCard title="Freigegeben" value={approved.length} hint="Bereits live in Paperless" accent="emerald" />
        <StatCard title="Blockiert" value={approvalState.blacklist.length} hint="Wird nicht erneut vorgeschlagen" accent="red" />
      </div>

      {#if feedback}
        <div class={`rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
          {feedback.message}
        </div>
      {/if}

      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Freigabe-Workflow</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">{title}</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{subtitle}</p>
          </div>
          <input bind:value={search} type="search" placeholder="Diese Freigaben durchsuchen …" class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-emerald-500/40" />
        </div>
      </Card>

      <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]">
        <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Entscheiden</p>
              <h3 class="mt-2 text-xl font-semibold text-white">Offene Vorschläge</h3>
            </div>
            <Badge color="yellow">{filteredPending.length} offen</Badge>
          </div>

          {#if filteredPending.length > 0}
            <div class="mt-5 space-y-3">
              {#each filteredPending as item}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4">
                  <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                      <div class="text-lg font-semibold text-white">{item.name}</div>
                      <div class="mt-3 grid gap-2 text-xs text-slate-400 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                          <span class="block text-[11px] uppercase tracking-wide text-slate-500">Häufigkeit</span>
                          <span class="mt-1 block text-slate-200">{item.times_seen} Sichtungen</span>
                        </div>
                        <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                          <span class="block text-[11px] uppercase tracking-wide text-slate-500">Zuerst gesehen</span>
                          <span class="mt-1 block text-slate-200">{formatDate(item.first_seen)}</span>
                        </div>
                      </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                      <Button size="sm" color="green" onclick={() => void runAction(`approve:${item.name}`, () => approve(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `approve:${item.name}` ? 'Freigabe …' : 'Freigeben'}
                      </Button>
                      <Button size="sm" color="red" onclick={() => void runAction(`reject:${item.name}`, () => reject(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `reject:${item.name}` ? 'Blockiert …' : 'Blockieren'}
                      </Button>
                    </div>
                  </div>
                </div>
              {/each}
            </div>
          {:else}
            <div class="mt-5">
              <EmptyState icon="✅" title="Nichts offen" description="Für diesen Bereich wartet aktuell nichts auf Freigabe." />
            </div>
          {/if}
        </Card>

        <aside class="space-y-6 xl:sticky xl:top-24 xl:self-start">
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Live</p>
                <h3 class="mt-2 text-xl font-semibold text-white">Freigegeben</h3>
              </div>
              <Badge color="green">{filteredApproved.length}</Badge>
            </div>
            <div class="mt-4 space-y-3">
              {#each filteredApproved.slice(0, 10) as item}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3">
                  <div class="font-medium text-white">{item.name}</div>
                  <div class="mt-1 text-xs text-slate-500">Paperless-ID: {item.paperless_id ?? '—'}</div>
                </div>
              {:else}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4 text-sm text-slate-400">Noch keine freigegebenen Einträge.</div>
              {/each}
            </div>
          </Card>

          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Blockliste</p>
                <h3 class="mt-2 text-xl font-semibold text-white">Blockiert</h3>
              </div>
              <Badge color="gray">{filteredBlacklist.length}</Badge>
            </div>
            <div class="mt-4 space-y-3">
              {#each filteredBlacklist.slice(0, 10) as item}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3">
                  <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                      <div class="font-medium text-white">{item.name}</div>
                      <div class="mt-1 text-xs text-slate-500">abgelehnt am {formatDate(item.rejected_at)}</div>
                    </div>
                    <Button size="xs" color="alternative" onclick={() => void runAction(`restore:${item.name}`, () => unblacklist(item.name))} disabled={busyKey !== ''}>
                      {busyKey === `restore:${item.name}` ? '…' : 'Entfernen'}
                    </Button>
                  </div>
                </div>
              {:else}
                <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4 text-sm text-slate-400">Keine blockierten Einträge.</div>
              {/each}
            </div>
          </Card>
        </aside>
      </div>
    </div>
  {/snippet}
</AppShell>
