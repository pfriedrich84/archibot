import type { PageLoad } from './$types';
import { loadEmbeddings } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const embeddings = await loadEmbeddings(fetch);
  return { embeddings };
};
