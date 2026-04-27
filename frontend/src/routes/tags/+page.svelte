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

  function approvedCount(items: ApprovalEntity[]) {
    return items.filter((item) => item.approved).length;
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

<AppShell title="Freigaben" subtitle="Neue Tags, Korrespondenten und Dokumenttypen gesammelt prüfen, freigeben oder gezielt blockieren.">
  {#snippet children()}
    {#if initialized}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      {#each sections as section}
        {@const state = snapshot[section.key] as ApprovalSnapshot}
        <StatCard title={`${section.title} offen`} value={pendingCount(state.whitelist)} hint={`${approvedCount(state.whitelist)} bereits freigegeben`} accent={section.accent} />
      {/each}
      <StatCard
        title="Blockiert gesamt"
        value={sections.reduce((sum, section) => sum + (snapshot[section.key] as ApprovalSnapshot).blacklist.length, 0)}
        hint="Kann bei Bedarf wieder freigegeben werden"
        accent="red"
      />
    </div>

    {#if feedback}
      <div class={`mt-6 rounded-2xl border p-4 text-sm ${feedback.type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-100' : 'border-rose-500/20 bg-rose-500/10 text-rose-100'}`}>
        {feedback.message}
      </div>
    {/if}

    <div class="mt-6 space-y-6">
      {#each sections as section}
        {@const state = snapshot[section.key] as ApprovalSnapshot}
        {@const pending = state.whitelist.filter((item) => !item.approved)}
        {@const approved = state.whitelist.filter((item) => item.approved)}
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(19rem,0.75fr)]">
          <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Freigaben</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">{section.title}</h2>
                <p class="mt-2 text-sm text-slate-400">{section.subtitle}</p>
              </div>
              <div class="flex flex-wrap gap-2">
                <Badge color="gray">{pending.length} offen</Badge>
                <Badge color="green">{approved.length} live</Badge>
              </div>
            </div>

            {#if pending.length > 0}
              <div class="mt-6 space-y-3">
                {#each pending as item}
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                      <div class="min-w-0">
                        <div class="font-medium text-white">{item.name}</div>
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
                        <Button size="sm" color="green" onclick={() => void runAction(`${section.key}:approve:${item.name}`, () => section.approve(item.name))} disabled={busyKey !== ''}>
                          {busyKey === `${section.key}:approve:${item.name}` ? 'Freigabe …' : 'Freigeben'}
                        </Button>
                        <Button size="sm" color="red" onclick={() => void runAction(`${section.key}:reject:${item.name}`, () => section.reject(item.name))} disabled={busyKey !== ''}>
                          {busyKey === `${section.key}:reject:${item.name}` ? 'Blockiert …' : 'Blockieren'}
                        </Button>
                      </div>
                    </div>
                  </div>
                {/each}
              </div>
            {:else}
              <div class="mt-6 rounded-3xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-emerald-100">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-200/70">Kein offener Stapel</p>
                <p class="mt-2 text-base font-medium">Für {section.title.toLowerCase()} wartet aktuell nichts auf Freigabe.</p>
                <p class="mt-2 text-sm text-emerald-50/80">Neue Vorschläge erscheinen hier automatisch, sobald Review-Läufe unbekannte Werte erzeugen.</p>
              </div>
            {/if}
          </Card>

          <div class="space-y-6 xl:sticky xl:top-24 xl:self-start">
            <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Bereits live</p>
                  <h3 class="mt-2 text-xl font-semibold text-white">Freigegebene Einträge</h3>
                </div>
                <Badge color="green">{approved.length}</Badge>
              </div>

              <div class="mt-4 space-y-3">
                {#each approved.slice(0, 6) as item}
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3">
                    <div class="font-medium text-white">{item.name}</div>
                    <div class="mt-1 text-xs text-slate-500">Paperless-ID: {item.paperless_id ?? '—'}</div>
                  </div>
                {:else}
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4 text-sm text-slate-400">Noch keine freigegebenen Einträge.</div>
                {/each}
              </div>
            </Card>

            <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Blockliste</p>
                  <h3 class="mt-2 text-xl font-semibold text-white">Blockierte Einträge</h3>
                </div>
                <Badge color="gray">{state.blacklist.length}</Badge>
              </div>

              <div class="mt-4 space-y-3">
                {#each state.blacklist.slice(0, 6) as item}
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-3">
                    <div class="flex items-center justify-between gap-3">
                      <div class="min-w-0">
                        <div class="font-medium text-white">{item.name}</div>
                        <div class="mt-1 text-xs text-slate-500">abgelehnt am {formatDate(item.rejected_at)}</div>
                      </div>
                      <Button size="xs" color="alternative" onclick={() => void runAction(`${section.key}:restore:${item.name}`, () => section.unblacklist(item.name))} disabled={busyKey !== ''}>
                        {busyKey === `${section.key}:restore:${item.name}` ? '…' : 'Entfernen'}
                      </Button>
                    </div>
                  </div>
                {:else}
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4 text-sm text-slate-400">Keine blockierten Einträge.</div>
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
