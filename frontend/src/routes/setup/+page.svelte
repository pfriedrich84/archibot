<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import PagePlaceholder from '$lib/components/PagePlaceholder.svelte';
</script>

<AppShell title="Setup" subtitle="Der Setup-Wizard bekommt später einen nativen Svelte-Flow.">
  {#snippet children()}
    <PagePlaceholder title="Setup Wizard" description="Verbindungstests, Schritt-für-Schritt-Onboarding und Validierung werden separat migriert." apiPath="/api/v1/setup/*" />
  {/snippet}
</AppShell>
