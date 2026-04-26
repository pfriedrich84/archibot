<script lang="ts">
  import { Card, Button } from 'flowbite-svelte';

  let { title, description, apiPath } = $props<{ title: string; description: string; apiPath?: string }>();
</script>

<Card class="rounded-3xl border border-dashed border-slate-700 bg-slate-900/60 shadow-lg shadow-slate-950/20">
  <div class="space-y-4">
    <div>
      <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Migration Workbench</p>
      <h2 class="mt-2 text-2xl font-semibold text-white">{title}</h2>
      <p class="mt-3 max-w-3xl text-sm text-slate-400">{description}</p>
    </div>

    {#if apiPath}
      <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
        <p class="text-sm text-slate-400">Planned API surface</p>
        <code class="mt-2 block text-sm text-emerald-300">{apiPath}</code>
      </div>
    {/if}

    <div class="flex flex-wrap gap-3">
      <Button color="dark" class="rounded-xl border border-slate-700">Flowbite page shell ready</Button>
      <Button color="alternative" class="rounded-xl">Functional parity pending</Button>
    </div>
  </div>
</Card>
