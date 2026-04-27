<script lang="ts">
  import { Badge, Button, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<AppShell title="Fehler" subtitle="Recent-Errors werden über den neuen JSON-Endpunkt mit operativer Sichtbarkeit dargestellt.">
  {#snippet children()}
    <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Operational Errors</p>
          <h2 class="mt-2 text-2xl font-semibold text-white">{data.errors.items.length} aktuelle Fehler</h2>
        </div>
        <a href="/errors" class="inline-flex"><Button color="dark" class="rounded-xl border border-slate-700">Legacy-Fehlerliste</Button></a>
      </div>

      <div class="mt-6 space-y-4">
        {#each data.errors.items as item}
          <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div>
                <div class="flex items-center gap-2">
                  <Badge color="red">{item.stage}</Badge>
                  {#if item.document_id}
                    <Badge color="gray">Dokument #{item.document_id}</Badge>
                  {/if}
                </div>
                <h3 class="mt-3 text-lg font-semibold text-white">{item.message}</h3>
                <p class="mt-2 text-sm text-slate-400">{item.details || 'Keine Zusatzdetails vorhanden.'}</p>
              </div>
              <div class="text-xs text-slate-500">{item.occurred_at}</div>
            </div>
          </div>
        {:else}
          <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-6 text-sm text-emerald-100">Keine aktuellen Fehler in der Datenbank.</div>
        {/each}
      </div>
    </Card>
  {/snippet}
</AppShell>
