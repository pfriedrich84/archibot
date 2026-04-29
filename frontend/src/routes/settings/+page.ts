import type { PageLoad } from './$types';
import { loadSettingsSchema } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const schema = await loadSettingsSchema(fetch);
  return { schema };
};
