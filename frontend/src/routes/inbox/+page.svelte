<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  let countEntries = $derived(Object.entries(data.inbox.counts));

  function statusColor(status: string) {
    if (status === 'error') return 'red';
    if (status === 'pending') return 'yellow';
    if (status === 'committed') return 'green';
    return 'gray';
  }
</script>

<AppShell title="Posteingang" subtitle="Neue Dokumente, Bearbeitungsstatus und Vorschläge schnell scannen, bevor du in Review oder Fehleranalyse wechselst.">
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Dokumente" value={data.inbox.total} hint="Geladene Inbox-Einträge" accent="blue" />
      <StatCard title="Pending" value={data.inbox.counts.pending ?? 0} hint="Warten auf Review" accent="emerald" />
      <StatCard title="Fehler" value={data.inbox.counts.error ?? 0} hint="Erfordern Aufmerksamkeit" accent="red" />
      <StatCard title="Committed" value={data.inbox.counts.committed ?? 0} hint="Bereits bestätigt" accent="purple" />
    </div>

    <Card size="xl" class="mt-6 rounded-3xl border border-slate-800/80 bg-slate-900/75 shadow-lg shadow-slate-950/20">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Inbox Snapshot</p>
          <h2 class="mt-2 text-2xl font-semibold text-white">Verarbeitungsstatus</h2>
          <p class="mt-2 text-sm text-slate-400">Nutze die Liste als schnelle Triage: problematische Dokumente zuerst, unklare Fälle danach in die Review Queue.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          {#each countEntries as [status, count]}
            <Badge color={statusColor(status)}>{status}: {count}</Badge>
          {/each}
          <a href="/app/review" class="inline-flex"><Button color="dark" class="rounded-xl border border-slate-700">Review öffnen</Button></a>
        </div>
      </div>

      {#if data.inbox.items.length > 0}
        <div class="mt-6 space-y-3">
          {#each data.inbox.items as item}
            <div class="rounded-2xl border border-slate-800/80 bg-slate-950/55 p-4">
              <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-white">Dokument #{item.document_id}</span>
                    {#if item.suggestion_id}
                      <span class="text-xs text-slate-500">Vorschlag #{item.suggestion_id}</span>
                    {/if}
                    <Badge color={statusColor(item.status)}>{item.status}</Badge>
                  </div>
                  <h3 class="mt-3 truncate text-lg font-semibold text-white">{item.proposed_title || 'Noch kein Vorschlag'}</h3>
                  <p class="mt-1 text-sm text-slate-400">{item.proposed_correspondent_name || 'Korrespondent offen'}</p>
                </div>
                <div class="grid gap-2 text-xs text-slate-400 sm:grid-cols-2 lg:w-[24rem]">
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Dokumenttyp</span>
                    <span class="mt-1 block text-slate-200">{item.proposed_doctype_name || 'Offen'}</span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Zuletzt verarbeitet</span>
                    <span class="mt-1 block text-slate-200">{item.last_processed || '—'}</span>
                  </div>
                </div>
              </div>
            </div>
          {/each}
        </div>
      {:else}
        <div class="mt-6 rounded-3xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-emerald-100">
          <p class="text-xs uppercase tracking-[0.2em] text-emerald-200/70">Kein Rückstau</p>
          <p class="mt-2 text-base font-medium">Aktuell sind keine Inbox-Daten verfügbar.</p>
          <p class="mt-2 text-sm text-emerald-50/80">Sobald neue Dokumente verarbeitet werden, erscheint hier wieder eine kompakte Triage-Ansicht.</p>
        </div>
      {/if}
    </Card>
  {/snippet}
</AppShell>
