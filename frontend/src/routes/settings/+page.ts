import type { PageLoad } from './$types';
import { loadDashboard, loadSettingsSchema, loadStatus } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, dashboard, status] = await Promise.all([
    loadSettingsSchema(fetch),
    loadDashboard(fetch),
    loadStatus(fetch)
  ]);
  return { schema, dashboard, status };
};
