import type { PageLoad } from './$types';
import { loadChat, loadDashboard, loadErrors, loadStatus } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [dashboard, status, errors, chat] = await Promise.all([
    loadDashboard(fetch),
    loadStatus(fetch),
    loadErrors(fetch),
    loadChat(fetch)
  ]);
  return { dashboard, status, errors, chat };
};
