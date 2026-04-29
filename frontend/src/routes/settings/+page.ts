import type { PageLoad } from './$types';
import { loadOllamaModelOptions, loadPaperlessTagOptions, loadSettingsSchema } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, paperlessTags, ollamaModels] = await Promise.all([
    loadSettingsSchema(fetch),
    loadPaperlessTagOptions(fetch).catch(() => ({ items: [] })),
    loadOllamaModelOptions(fetch).catch(() => ({ items: [] }))
  ]);
  return { schema, paperlessTags, ollamaModels };
};
