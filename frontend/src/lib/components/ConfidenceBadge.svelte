<script lang="ts">
  import { Badge } from 'flowbite-svelte';

  let { confidence = null, compact = false } = $props<{
    confidence?: number | null;
    compact?: boolean;
  }>();

  let level = $derived.by(() => {
    if (confidence === null || confidence === undefined) return { label: 'Keine Konfidenz', color: 'gray', className: 'border-slate-700 bg-slate-800/70 text-slate-300' };
    if (confidence >= 90) return { label: 'Sehr sicher', color: 'green', className: 'border-emerald-500/35 bg-emerald-500/12 text-emerald-100' };
    if (confidence >= 75) return { label: 'Sicher', color: 'blue', className: 'border-sky-500/35 bg-sky-500/12 text-sky-100' };
    if (confidence >= 50) return { label: 'Prüfen', color: 'yellow', className: 'border-amber-500/35 bg-amber-500/12 text-amber-100' };
    return { label: 'Unsicher', color: 'red', className: 'border-rose-500/35 bg-rose-500/12 text-rose-100' };
  });
</script>

<Badge color={level.color as 'gray'} class={`rounded-full border px-2.5 py-1 ${level.className}`} title={confidence === null ? 'Keine Konfidenz vorhanden' : `Konfidenz: ${confidence}%`}>
  {#if confidence === null || confidence === undefined}
    —
  {:else if compact}
    {confidence}%
  {:else}
    {confidence}% · {level.label}
  {/if}
</Badge>
