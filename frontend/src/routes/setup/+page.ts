import type { PageLoad } from './$types';
import { loadSettingsSchema, loadStatus } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, status] = await Promise.all([loadSettingsSchema(fetch), loadStatus(fetch)]);
  return { schema, status };
};
