<script lang="ts">
  import ApprovalPage from '$lib/components/ApprovalPage.svelte';
  import { approveCorrespondent, rejectCorrespondent, unblacklistCorrespondent } from '$lib/api';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<ApprovalPage
  title="Korrespondenten"
  subtitle="Neue Korrespondenten separat prüfen und in Paperless übernehmen oder dauerhaft blockieren."
  navKey="correspondents"
  snapshotKey="correspondents"
  accent="emerald"
  approve={approveCorrespondent}
  reject={rejectCorrespondent}
  unblacklist={unblacklistCorrespondent}
  initial={data.tags}
/>
