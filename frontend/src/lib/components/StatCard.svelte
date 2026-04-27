<script lang="ts">
  import { Card, Badge } from 'flowbite-svelte';

  let { title, value, hint = '', trend = '', accent = 'emerald' } = $props<{
    title: string;
    value: string | number;
    hint?: string;
    trend?: string;
    accent?: 'emerald' | 'blue' | 'purple' | 'red';
  }>();

  const accentClasses: Record<'emerald' | 'blue' | 'purple' | 'red', string> = {
    emerald: 'from-emerald-500/20 to-emerald-500/0 border-emerald-500/20',
    blue: 'from-blue-500/20 to-blue-500/0 border-blue-500/20',
    purple: 'from-purple-500/20 to-purple-500/0 border-purple-500/20',
    red: 'from-red-500/20 to-red-500/0 border-red-500/20'
  };
</script>

<Card size="xl" class={`h-full rounded-3xl border bg-gradient-to-br ${accentClasses[accent as keyof typeof accentClasses]} bg-slate-900/70 shadow-lg shadow-slate-950/20`}>
  <div class="flex items-start justify-between gap-3">
    <div>
      <p class="text-sm text-slate-400">{title}</p>
      <p class="mt-3 text-4xl font-semibold text-white">{value}</p>
      {#if hint}
        <p class="mt-3 text-sm text-slate-400">{hint}</p>
      {/if}
    </div>
    {#if trend}
      <Badge color="gray" class="rounded-full border border-slate-700 bg-slate-950/60 text-slate-200">{trend}</Badge>
    {/if}
  </div>
</Card>
