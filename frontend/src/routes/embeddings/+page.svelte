<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import PagePlaceholder from '$lib/components/PagePlaceholder.svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<AppShell title="Embeddings" subtitle="Indexabdeckung und zuletzt eingebettete Dokumente prüfen, ohne durch rohe Tabellen scrollen zu müssen.">
  {#snippet children()}
    <div class="grid gap-4 md:grid-cols-3">
      <StatCard title="Indexierte Dokumente" value={data.embeddings.total_embedded} hint="Metadatensätze im Vektorindex" accent="emerald" />
      <StatCard title="Sichtbare Zeilen" value={data.embeddings.items.length} hint="Aktuell geladen" accent="blue" />
      <StatCard title="Metadaten" value="Namen" hint="Korrespondent, Typ, Pfad und Tags" accent="purple" />
    </div>

    {#if data.embeddings.items.length > 0}
      <Card size="xl" class="mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Embedding Index</p>
            <h2 class="mt-2 text-lg font-semibold text-white">Neueste Einträge</h2>
          </div>
          <Badge color="gray">{data.embeddings.total_embedded} total</Badge>
        </div>

        <div class="mt-5 space-y-2.5">
          {#each data.embeddings.items as item}
            <div class="rounded-2xl border border-slate-800/80 bg-slate-950/55 p-3.5">
              <div class="flex flex-col gap-2.5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                  <div class="text-sm font-medium text-white">Dokument #{item.document_id}</div>
                  <h3 class="mt-1.5 truncate text-base font-semibold text-white">{item.title || 'Unbenanntes Dokument'}</h3>
                </div>
                <div class="grid gap-2 text-xs text-slate-400 sm:grid-cols-2 xl:grid-cols-3 lg:w-[34rem]">
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Korrespondent</span>
                    <span class="mt-1 block text-slate-200">{item.correspondent_name ?? item.correspondent ?? '—'}</span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Dokumenttyp</span>
                    <span class="mt-1 block text-slate-200">{item.doctype_name ?? item.doctype ?? '—'}</span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Speicherpfad</span>
                    <span class="mt-1 block text-slate-200">{item.storage_path_name ?? item.storage_path ?? '—'}</span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2 sm:col-span-2 xl:col-span-3">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Tags</span>
                    <span class="mt-1 flex flex-wrap gap-1.5 text-slate-200">
                      {#each item.tags as tag}
                        <span class="rounded-full border border-slate-700 bg-slate-900 px-2 py-0.5">{tag}</span>
                      {:else}
                        <span>—</span>
                      {/each}
                    </span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Erstellt</span>
                    <span class="mt-1 block text-slate-200">{item.created_date || '—'}</span>
                  </div>
                  <div class="rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2 sm:col-span-1 xl:col-span-2">
                    <span class="block text-[11px] uppercase tracking-wide text-slate-500">Indexiert</span>
                    <span class="mt-1 block text-slate-200">{item.indexed_at}</span>
                  </div>
                </div>
              </div>
            </div>
          {/each}
        </div>
      </Card>
    {:else}
      <div class="mt-4">
        <PagePlaceholder
          title="Noch keine Embedding-Metadaten verfügbar"
          description="Sobald Dokumente in den Embedding-Index aufgenommen wurden, erscheint hier eine kompakte Inspektionsansicht mit den zuletzt indexierten Einträgen."
        />
      </div>
    {/if}
  {/snippet}
</AppShell>
