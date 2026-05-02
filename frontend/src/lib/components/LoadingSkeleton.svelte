<script lang="ts">
  let { rows = 3 } = $props<{ rows?: number }>();
</script>

<div class="space-y-3" aria-label="Inhalt wird geladen">
  {#each Array.from({ length: rows }) as _}
    <div class="animate-pulse rounded-2xl border border-slate-800/80 bg-slate-900/60 p-4">
      <div class="h-4 w-1/3 rounded-full bg-slate-700/70"></div>
      <div class="mt-4 h-3 w-full rounded-full bg-slate-800"></div>
      <div class="mt-2 h-3 w-2/3 rounded-full bg-slate-800"></div>
    </div>
  {/each}
</div>
