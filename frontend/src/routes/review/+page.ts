import type { PageLoad } from './$types';
import { loadReviewQueue } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const review = await loadReviewQueue(fetch);
  return { review };
};
