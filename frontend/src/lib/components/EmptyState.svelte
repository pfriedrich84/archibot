<script lang="ts">
  import { Button } from 'flowbite-svelte';

  let { icon = '✨', title, description = '', actionHref = '', actionLabel = '' } = $props<{
    icon?: string;
    title: string;
    description?: string;
    actionHref?: string;
    actionLabel?: string;
  }>();
</script>

<div class="rounded-2xl border border-slate-800/80 bg-slate-900/65 p-6 text-center shadow-lg shadow-slate-950/20">
  <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-700 bg-slate-950/70 text-lg" aria-hidden="true">
    {icon}
  </div>
  <h2 class="mt-5 text-xl font-semibold text-white">{title}</h2>
  {#if description}
    <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-400">{description}</p>
  {/if}
  {#if actionHref && actionLabel}
    <a href={actionHref} class="mt-5 inline-flex"><Button color="green" class="rounded-xl">{actionLabel}</Button></a>
  {/if}
</div>
