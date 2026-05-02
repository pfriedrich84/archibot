<script lang="ts">
  import { Card } from 'flowbite-svelte';

  let { title, description, apiPath } = $props<{ title: string; description: string; apiPath?: string }>();
</script>

<Card size="xl" class="rounded-2xl border border-dashed border-slate-700/80 bg-slate-900/55 shadow-lg shadow-slate-950/20">
  <div class="space-y-4">
    <div>
      <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Arbeitsbereich in Überarbeitung</p>
      <h2 class="mt-2 text-lg font-semibold text-white">{title}</h2>
      <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{description}</p>
    </div>

    {#if apiPath}
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Relevanter API-Pfad</p>
        <code class="mt-2 block text-sm text-emerald-300">{apiPath}</code>
      </div>
    {/if}

    <div class="flex flex-wrap gap-3 text-sm text-slate-400">
      <span class="rounded-full border border-slate-700 bg-slate-950/70 px-3 py-1.5">Flowbite-Seite vorhanden</span>
      <span class="rounded-full border border-slate-700 bg-slate-950/70 px-3 py-1.5">Feinschliff oder Funktionsausbau folgt</span>
    </div>
  </div>
</Card>
