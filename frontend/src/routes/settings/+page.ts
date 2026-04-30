import type { PageLoad } from './$types';
import { loadOllamaModelOptions, loadPaperlessTagOptions, loadPrompts, loadSettingsSchema } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const [schema, paperlessTags, ollamaModels, prompts] = await Promise.all([
    loadSettingsSchema(fetch),
    loadPaperlessTagOptions(fetch).catch(() => ({ items: [] })),
    loadOllamaModelOptions(fetch).catch(() => ({ items: [] })),
    loadPrompts(fetch).catch(() => ({ items: [], max_chars: 0 }))
  ]);
  return { schema, paperlessTags, ollamaModels, prompts };
};
