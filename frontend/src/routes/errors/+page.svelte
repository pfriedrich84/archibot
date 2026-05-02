<script lang="ts">
  import { Badge, Card } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  function formatDateTime(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    }).format(date);
  }
</script>

<AppShell title="Fehler" subtitle="Aktuelle Fehlersignale schnell triagieren und den nächsten sinnvollen Schritt in Review oder Inbox ableiten." navBadges={{ errors: data.errors.items.length }}>
  {#snippet children()}
    <Card size="xl" class="rounded-2xl border border-slate-800/80 bg-slate-900/75 p-4 shadow-lg shadow-slate-950/20">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Operational Errors</p>
          <h2 class="mt-2 text-lg font-semibold text-white">{data.errors.items.length} aktuelle Fehler</h2>
          <p class="mt-2 text-sm text-slate-400">Konzentriere dich zuerst auf Stage, betroffenes Dokument und Zeitpunkt. Danach entscheiden Review oder Inbox über die nächste Aktion.</p>
        </div>
      </div>

      <div class="mt-4 space-y-4">
        {#each data.errors.items as item}
          <div class="rounded-2xl border border-slate-800/80 bg-slate-950/60 p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <Badge color="red">{item.stage}</Badge>
                  {#if item.document_id}
                    <Badge color="gray">Dokument #{item.document_id}</Badge>
                  {/if}
                </div>
                <h3 class="mt-3 text-lg font-semibold text-white">{item.message}</h3>
                <p class="mt-2 text-sm text-slate-400">{item.details || 'Keine Zusatzdetails vorhanden.'}</p>
              </div>
              <div class="shrink-0 rounded-2xl border border-slate-800/80 bg-slate-950/50 px-3 py-2 text-xs text-slate-400">{formatDateTime(item.occurred_at)}</div>
            </div>
          </div>
        {:else}
          <EmptyState icon="🟢" title="Keine aktuellen Fehler" description="Die Fehlerliste ist leer. Falls ein Polling- oder Reindex-Job scheitert, erscheint hier ein triagierbarer Eintrag mit nächster Aktion." />
        {/each}
      </div>
    </Card>
  {/snippet}
</AppShell>
