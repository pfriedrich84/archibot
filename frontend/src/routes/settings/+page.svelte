<script lang="ts">
  import { Card, Badge } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<AppShell title="Einstellungen" subtitle="Schema-basiertes Rendering auf Basis von /api/v1/settings/schema.">
  {#snippet children()}
    <div class="space-y-6">
      {#each data.schema.categories as category}
        <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Settings Category</p>
              <h2 class="mt-2 text-2xl font-semibold text-white">{category.name}</h2>
            </div>
            <Badge color="gray">{category.fields.length} Felder</Badge>
          </div>

          <div class="mt-6 grid gap-4 md:grid-cols-2">
            {#each category.fields as field}
              <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium text-white">{field.label}</p>
                    <p class="mt-1 text-xs text-slate-500">{field.name}</p>
                  </div>
                  {#if field.restart}
                    <Badge color="yellow">Restart</Badge>
                  {:else}
                    <Badge color="green">Live</Badge>
                  {/if}
                </div>

                <p class="mt-3 text-sm text-slate-400">{field.help}</p>
                <div class="mt-4 text-sm text-slate-300">
                  {#if field.sensitive}
                    {#if field.configured}
                      <span class="text-emerald-300">Konfiguriert</span>
                    {:else}
                      <span class="text-amber-300">Nicht gesetzt</span>
                    {/if}
                  {:else}
                    <code class="break-all text-emerald-300">{String(field.value)}</code>
                  {/if}
                </div>
              </div>
            {/each}
          </div>
        </Card>
      {/each}
    </div>
  {/snippet}
</AppShell>
