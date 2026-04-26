<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<AppShell title="Review Queue" subtitle="Aktive Vorschläge werden jetzt als Flowbite-Tabelle geladen. Für Commit/Edit bleibt die Legacy-Ansicht vorerst direkt erreichbar.">
  {#snippet children()}
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
      <Card class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Moderation Queue</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">{data.review.total} offene Vorschläge</h2>
            <p class="mt-2 text-sm text-slate-400">Neueste Pending-Suggestions pro Dokument, optimiert für die spätere Batch-Freigabe.</p>
          </div>
          <a href="/review" class="inline-flex">
            <Button color="dark" class="rounded-xl border border-slate-700">Legacy-Review öffnen</Button>
          </a>
        </div>

        <div class="mt-6 overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-800 text-sm text-slate-200">
            <thead class="text-left text-xs uppercase tracking-[0.2em] text-slate-500">
              <tr>
                <th class="px-4 py-3">Dokument</th>
                <th class="px-4 py-3">Vorschlag</th>
                <th class="px-4 py-3">Typ</th>
                <th class="px-4 py-3">Vertrauen</th>
                <th class="px-4 py-3">Judge</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-900/80">
              {#each data.review.items as item}
                <tr class="bg-slate-950/40">
                  <td class="px-4 py-4 align-top">
                    <div class="font-medium text-white">#{item.document_id}</div>
                    <div class="mt-1 text-xs text-slate-500">Suggestion #{item.id}</div>
                    <div class="mt-2"><Badge color="gray">{item.document_status ?? 'unbekannt'}</Badge></div>
                  </td>
                  <td class="px-4 py-4 align-top">
                    <div class="font-medium text-white">{item.proposed_title || 'Ohne Titelvorschlag'}</div>
                    <div class="mt-1 text-slate-400">{item.proposed_correspondent_name || 'Korrespondent offen'}</div>
                    {#if item.proposed_storage_path_name}
                      <div class="mt-1 text-xs text-slate-500">Pfad: {item.proposed_storage_path_name}</div>
                    {/if}
                  </td>
                  <td class="px-4 py-4 align-top text-slate-300">{item.proposed_doctype_name || 'Nicht zugeordnet'}</td>
                  <td class="px-4 py-4 align-top">
                    <Badge color={item.confidence !== null && item.confidence >= 80 ? 'green' : item.confidence !== null && item.confidence >= 50 ? 'yellow' : 'gray'}>
                      {item.confidence ?? '—'}{item.confidence !== null ? '%' : ''}
                    </Badge>
                  </td>
                  <td class="px-4 py-4 align-top text-slate-300">{item.judge_verdict || '—'}</td>
                </tr>
              {:else}
                <tr>
                  <td colspan="5" class="px-4 py-8 text-center text-slate-400">Keine offenen Vorschläge.</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </Card>

      <Card class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Migration Status</p>
        <h2 class="mt-2 text-2xl font-semibold text-white">Read-only Svelte Slice</h2>
        <ul class="mt-4 space-y-3 text-sm text-slate-400">
          <li>• Queue-Daten kommen aus <code class="text-emerald-300">/api/v1/review/queue</code></li>
          <li>• Nächster Schritt: Filter, Detail-Sidepanel und Bulk-Aktionen</li>
          <li>• Legacy-Review bleibt für Schreiben/Commit aktiv</li>
        </ul>
      </Card>
    </div>
  {/snippet}
</AppShell>
