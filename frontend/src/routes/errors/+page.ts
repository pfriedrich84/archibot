import type { PageLoad } from './$types';
import { loadErrors } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const errors = await loadErrors(fetch);
  return { errors };
};
