import type { PageLoad } from './$types';
import { loadPaperlessTagOptions, loadSettingsSchema } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, paperlessTags] = await Promise.all([
    loadSettingsSchema(fetch),
    loadPaperlessTagOptions(fetch).catch(() => ({ items: [] }))
  ]);
  return { schema, paperlessTags };
};
