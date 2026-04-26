import type { PageLoad } from './$types';
import { loadStats } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const stats = await loadStats(fetch);
  return { stats };
};
