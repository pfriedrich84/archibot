import type { PageLoad } from './$types';
import { loadChat } from '$lib/api';

export const load: PageLoad = async ({ fetch }) => {
  const chat = await loadChat(fetch);
  return { chat };
};
