<script lang="ts">
  import ApprovalPage from '$lib/components/ApprovalPage.svelte';
  import { approveDoctype, rejectDoctype, unblacklistDoctype } from '$lib/api';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<ApprovalPage
  title="Dokumenttypen"
  subtitle="Neue Dokumenttypen separat prüfen, freigeben oder blockieren — ohne Tags und Korrespondenten auf derselben Seite."
  navKey="doctypes"
  snapshotKey="doctypes"
  accent="purple"
  approve={approveDoctype}
  reject={rejectDoctype}
  unblacklist={unblacklistDoctype}
  initial={data.tags}
/>
