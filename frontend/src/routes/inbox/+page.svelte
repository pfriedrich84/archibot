<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  let countEntries = $derived(Object.entries(data.inbox.counts));
</script>

<AppShell title="Posteingang" subtitle="Verarbeitungsstatus und neueste Vorschläge werden als tabletaugliche Übersicht geladen.">
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Dokumente" value={data.inbox.total} hint="Geladene Inbox-Einträge" accent="blue" />
      <StatCard title="Pending" value={data.inbox.counts.pending ?? 0} hint="Warten auf Review" accent="emerald" />
      <StatCard title="Fehler" value={data.inbox.counts.error ?? 0} hint="Erfordern Aufmerksamkeit" accent="red" />
      <StatCard title="Committed" value={data.inbox.counts.committed ?? 0} hint="Bereits bestätigt" accent="purple" />
    </div>

    <Card size="xl" class="mt-6 rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Inbox Snapshot</p>
          <h2 class="mt-2 text-2xl font-semibold text-white">Verarbeitungsstatus</h2>
        </div>
        <div class="flex flex-wrap gap-2">
          {#each countEntries as [status, count]}
            <Badge color="gray">{status}: {count}</Badge>
          {/each}
        </div>
      </div>

      <div class="mt-6 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-800 text-sm text-slate-200">
          <thead class="text-left text-xs uppercase tracking-[0.2em] text-slate-500">
            <tr>
              <th class="px-4 py-3">Dokument</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Vorschlag</th>
              <th class="px-4 py-3">Typ</th>
              <th class="px-4 py-3">Zuletzt verarbeitet</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-900/80">
            {#each data.inbox.items as item}
              <tr class="bg-slate-950/40">
                <td class="px-4 py-4 align-top">
                  <div class="font-medium text-white">#{item.document_id}</div>
                  {#if item.suggestion_id}
                    <div class="mt-1 text-xs text-slate-500">Suggestion #{item.suggestion_id}</div>
                  {/if}
                </td>
                <td class="px-4 py-4 align-top"><Badge color="gray">{item.status}</Badge></td>
                <td class="px-4 py-4 align-top">
                  <div class="font-medium text-white">{item.proposed_title || 'Noch kein Vorschlag'}</div>
                  <div class="mt-1 text-slate-400">{item.proposed_correspondent_name || 'Korrespondent offen'}</div>
                </td>
                <td class="px-4 py-4 align-top text-slate-300">{item.proposed_doctype_name || '—'}</td>
                <td class="px-4 py-4 align-top text-slate-400">{item.last_processed || '—'}</td>
              </tr>
            {:else}
              <tr>
                <td colspan="5" class="px-4 py-8 text-center text-slate-400">Keine Inbox-Daten verfügbar.</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    </Card>
  {/snippet}
</AppShell>
