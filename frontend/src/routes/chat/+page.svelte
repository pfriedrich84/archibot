<script lang="ts">
  import AppShell from '$lib/components/AppShell.svelte';
  import PagePlaceholder from '$lib/components/PagePlaceholder.svelte';
</script>

<AppShell title="Chat" subtitle="Der RAG-Chat erhält eine neue Chat-UI, sobald die Admin-Basis steht.">
  {#snippet children()}
    <PagePlaceholder title="Chat" description="Streaming-Antworten, Konversationshistorie und Dokument-Referenzen sind für die Svelte-Version vorgesehen." apiPath="/api/v1/chat/*" />
  {/snippet}
</AppShell>
