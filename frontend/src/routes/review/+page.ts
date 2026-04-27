import type { PageLoad } from './$types';
import { loadReviewQueue } from '$lib/api';

export const load: PageLoad = async ({ fetch, url }) => {
  const review = await loadReviewQueue(fetch, url.searchParams);
  return {
    review,
    urlState: {
      page: Number(url.searchParams.get('page') || '1'),
      perPage: Number(url.searchParams.get('per_page') || '25'),
      minConfidence: Number(url.searchParams.get('min_conf') || '0'),
      maxConfidence: Number(url.searchParams.get('max_conf') || '100'),
      sort: url.searchParams.get('sort') || 'created_desc',
      judgeVerdict: url.searchParams.get('judge_verdict') || '',
      correspondentId: url.searchParams.get('correspondent_id') || ''
    }
  };
};
