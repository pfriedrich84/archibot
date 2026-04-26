import type { PageLoad } from './$types';
import { loadInbox } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const inbox = await loadInbox(fetch);
  return { inbox };
};
