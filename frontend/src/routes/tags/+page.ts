import type { PageLoad } from './$types';
import { loadTags } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const tags = await loadTags(fetch);
  return { tags };
};
