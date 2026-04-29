import type { PageLoad } from './$types';
import { loadOllamaModelOptions, loadPaperlessTagOptions, loadSettingsSchema, loadStatus } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, status, paperlessTags, ollamaModels] = await Promise.all([
    loadSettingsSchema(fetch),
    loadStatus(fetch),
    loadPaperlessTagOptions(fetch).catch(() => ({ items: [] })),
    loadOllamaModelOptions(fetch).catch(() => ({ items: [] }))
  ]);
  return { schema, status, paperlessTags, ollamaModels };
};
