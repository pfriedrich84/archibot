import type { PageLoad } from './$types';
import { loadDashboard, loadStatus } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [dashboard, status] = await Promise.all([loadDashboard(fetch), loadStatus(fetch)]);
  return { dashboard, status };
};
