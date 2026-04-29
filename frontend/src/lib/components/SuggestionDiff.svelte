<script lang="ts">
  let { label, original = null, proposed = null, changed = false } = $props<{
    label: string;
    original?: string | null;
    proposed?: string | null;
    changed?: boolean;
  }>();
</script>

<div class={`rounded-2xl border p-3.5 ${changed ? 'border-amber-500/30 bg-amber-500/10' : 'border-slate-800/80 bg-slate-950/55'}`}>
  <div class="flex items-center justify-between gap-3">
    <p class="text-xs font-medium uppercase tracking-[0.18em] text-slate-500">{label}</p>
    {#if changed}
      <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-100">geändert</span>
    {/if}
  </div>
  <div class="mt-3 grid gap-3 md:grid-cols-2">
    <div>
      <p class="text-[11px] uppercase tracking-wide text-slate-600">Original</p>
      <p class={`mt-1 text-sm ${changed ? 'text-slate-500 line-through' : 'text-slate-200'}`}>{original || '—'}</p>
    </div>
    <div>
      <p class="text-[11px] uppercase tracking-wide text-slate-600">Vorschlag</p>
      <p class="mt-1 text-sm font-medium text-white">{proposed || '—'}</p>
    </div>
  </div>
</div>
