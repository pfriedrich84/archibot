<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<AppShell title="Embeddings" subtitle="Index-Metadaten werden bereits nativ in der Svelte-Admin-Oberfläche angezeigt.">
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-3">
      <StatCard title="Indexed Docs" value={data.embeddings.total_embedded} hint="Metadatensätze im Vektorindex" accent="emerald" />
      <StatCard title="Visible Rows" value={data.embeddings.items.length} hint="Aktuell geladene Tabelle" accent="blue" />
      <StatCard title="Search UX" value="Nächster Schritt" hint="Similarity und Filter folgen" accent="purple" />
    </div>

    <Card size="xl" class="mt-6 rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embedding Index</p>
          <h2 class="mt-2 text-2xl font-semibold text-white">Neueste Einträge</h2>
        </div>
        <Badge color="gray">{data.embeddings.total_embedded} total</Badge>
      </div>

      <div class="mt-6 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-800 text-sm text-slate-200">
          <thead class="text-left text-xs uppercase tracking-[0.2em] text-slate-500">
            <tr>
              <th class="px-4 py-3">Dokument</th>
              <th class="px-4 py-3">Titel</th>
              <th class="px-4 py-3">Created</th>
              <th class="px-4 py-3">Indexed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-900/80">
            {#each data.embeddings.items as item}
              <tr class="bg-slate-950/40">
                <td class="px-4 py-4 text-white">#{item.document_id}</td>
                <td class="px-4 py-4">{item.title || 'Unbenanntes Dokument'}</td>
                <td class="px-4 py-4 text-slate-400">{item.created_date || '—'}</td>
                <td class="px-4 py-4 text-slate-400">{item.indexed_at}</td>
              </tr>
            {:else}
              <tr>
                <td colspan="4" class="px-4 py-8 text-center text-slate-400">Noch keine Embedding-Metadaten vorhanden.</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    </Card>
  {/snippet}
</AppShell>
