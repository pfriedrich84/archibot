<script lang="ts">
  import ApprovalPage from '$lib/components/ApprovalPage.svelte';
  import { approveTag, rejectTag, unblacklistTag } from '$lib/api';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
</script>

<ApprovalPage
  title="Tags"
  subtitle="Neue Tags aus Vorschlägen gezielt freigeben oder blockieren. Nur dieser Freigabebereich wird hier gezeigt."
  navKey="tags"
  snapshotKey="tags"
  accent="blue"
  approve={approveTag}
  reject={rejectTag}
  unblacklist={unblacklistTag}
  initial={data.tags}
/>
