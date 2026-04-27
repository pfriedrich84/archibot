<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  let pendingCount = $derived(data.tags.whitelist.filter((tag: (typeof data.tags.whitelist)[number]) => !tag.approved).length);
  let approvedCount = $derived(data.tags.whitelist.filter((tag: (typeof data.tags.whitelist)[number]) => tag.approved).length);
</script>

<AppShell title="Tags" subtitle="Whitelist- und Blacklist-Daten sind jetzt als strukturierte Admin-Ansichten verfügbar.">
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-3">
      <StatCard title="Ausstehend" value={pendingCount} hint="Tags zur Freigabe" accent="blue" />
      <StatCard title="Freigegeben" value={approvedCount} hint="Bereits in Paperless vorhanden" accent="emerald" />
      <StatCard title="Blockiert" value={data.tags.blacklist.length} hint="Blacklist-Einträge" accent="red" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Whitelist</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Freigabe-Queue</h2>
          </div>
          <Badge color="gray">{data.tags.whitelist.length}</Badge>
        </div>

        <div class="mt-6 space-y-3">
          {#each data.tags.whitelist as tag}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-medium text-white">{tag.name}</div>
                  <div class="mt-1 text-xs text-slate-500">gesehen: {tag.times_seen} · first_seen: {tag.first_seen}</div>
                </div>
                <Badge color={tag.approved ? 'green' : 'yellow'}>{tag.approved ? 'Approved' : 'Pending'}</Badge>
              </div>
              {#if tag.paperless_id}
                <div class="mt-2 text-sm text-slate-400">Paperless-ID: {tag.paperless_id}</div>
              {/if}
            </div>
          {:else}
            <p class="text-sm text-slate-400">Keine Whitelist-Einträge.</p>
          {/each}
        </div>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800 bg-slate-900/80 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Blacklist</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Abgelehnte Tags</h2>
          </div>
          <Badge color="gray">{data.tags.blacklist.length}</Badge>
        </div>

        <div class="mt-6 space-y-3">
          {#each data.tags.blacklist as tag}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
              <div class="font-medium text-white">{tag.name}</div>
              <div class="mt-1 text-xs text-slate-500">times_seen: {tag.times_seen} · rejected_at: {tag.rejected_at}</div>
            </div>
          {:else}
            <p class="text-sm text-slate-400">Keine Blacklist-Einträge.</p>
          {/each}
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
