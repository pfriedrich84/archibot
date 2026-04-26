<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import StatCard from '$lib/components/StatCard.svelte';
  import StatusPanel from '$lib/components/StatusPanel.svelte';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

</script>

<AppShell
  title="Dashboard"
  subtitle="Neue Admin-Oberfläche auf SvelteKit-Basis. Die Kernmetriken kommen bereits aus /api/v1/dashboard und /api/v1/system/status."
>
  {#snippet children()}
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
      <StatCard title="Pending Review" value={data.dashboard.kpis.pending_review} hint="Vorschläge warten auf Freigabe" accent="emerald" />
      <StatCard title="Errors (24h)" value={data.dashboard.kpis.errors_24h} hint="Fehler der letzten 24 Stunden" accent="red" />
      <StatCard title="Inbox Pending" value={data.dashboard.kpis.inbox_pending} hint="Dokumente im Posteingang" accent="blue" />
      <StatCard
        title="Committed Today"
        value={data.dashboard.kpis.committed_today}
        hint="Heute bestätigte Dokumente"
        accent="purple"
      />
    </div>

    <div class="mt-6">
      <StatusPanel dashboard={data.dashboard} status={data.status} />
    </div>
  {/snippet}
</AppShell>
