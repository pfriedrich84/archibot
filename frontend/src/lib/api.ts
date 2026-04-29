import type {
  ChatPayload,
  DashboardPayload,
  EmbeddingsPayload,
  ErrorsPayload,
  InboxPayload,
  ReviewBulkMutationResponse,
  ReviewDetailPayload,
  ReviewMutationResponse,
  ReviewQueuePayload,
  SettingsSchemaPayload,
  StatsPayload,
  StatusPayload,
  TagsPayload
} from '$lib/types';

const baseUrl = (import.meta.env.PUBLIC_ARCHIBOT_API_BASE_URL as string | undefined) || '';

async function apiFetch<T>(path: string, fetcher: typeof fetch): Promise<T> {
  const response = await fetcher(`${baseUrl}${path}`);
  if (!response.ok) {
    throw new Error(`API request failed for ${path}: ${response.status}`);
  }
  return (await response.json()) as T;
}

function getCsrfToken(): string {
  if (typeof document === 'undefined') {
    return '';
  }

  const token = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('csrf_token='))
    ?.split('=')[1];

  return token ? decodeURIComponent(token) : '';
}

async function apiMutation<T>(path: string, body: object): Promise<T> {
  const response = await fetch(`${baseUrl}${path}`, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      accept: 'application/json',
      'X-CSRF-Token': getCsrfToken()
    },
    body: JSON.stringify(body)
  });

  if (!response.ok) {
    const detail = await response.text();
    throw new Error(detail || `API mutation failed for ${path}: ${response.status}`);
  }

  return (await response.json()) as T;
}

export const loadDashboard = (fetcher: typeof fetch) => apiFetch<DashboardPayload>('/api/v1/dashboard', fetcher);
export const loadStatus = (fetcher: typeof fetch) => apiFetch<StatusPayload>('/api/v1/system/status', fetcher);
export const loadErrors = (fetcher: typeof fetch) => apiFetch<ErrorsPayload>('/api/v1/errors/recent', fetcher);
export const loadReviewQueue = (fetcher: typeof fetch, params?: URLSearchParams) =>
  apiFetch<ReviewQueuePayload>(`/api/v1/review/queue${params && params.toString() ? `?${params.toString()}` : ''}`, fetcher);
export const loadReviewDetail = (suggestionId: number, fetcher: typeof fetch) =>
  apiFetch<ReviewDetailPayload>(`/api/v1/review/${suggestionId}`, fetcher);
export const saveReviewSuggestion = (suggestionId: number, payload: object) =>
  apiMutation<ReviewMutationResponse>(`/api/v1/review/${suggestionId}/save`, payload);
export const acceptReviewSuggestion = (suggestionId: number, payload: object) =>
  apiMutation<ReviewMutationResponse>(`/api/v1/review/${suggestionId}/accept`, payload);
export const rejectReviewSuggestion = (suggestionId: number) =>
  apiMutation<ReviewMutationResponse>(`/api/v1/review/${suggestionId}/reject`, {});
export const bulkAcceptReviewSuggestions = (suggestionIds: number[]) =>
  apiMutation<ReviewBulkMutationResponse>('/api/v1/review/bulk/accept', { suggestion_ids: suggestionIds });
export const bulkRejectReviewSuggestions = (suggestionIds: number[]) =>
  apiMutation<ReviewBulkMutationResponse>('/api/v1/review/bulk/reject', { suggestion_ids: suggestionIds });
export const loadInbox = (fetcher: typeof fetch) => apiFetch<InboxPayload>('/api/v1/inbox', fetcher);
export const loadTags = (fetcher: typeof fetch) => apiFetch<TagsPayload>('/api/v1/tags', fetcher);
export const loadStats = (fetcher: typeof fetch) => apiFetch<StatsPayload>('/api/v1/stats', fetcher);
export const loadEmbeddings = (fetcher: typeof fetch) => apiFetch<EmbeddingsPayload>('/api/v1/embeddings', fetcher);
export const loadChat = (fetcher: typeof fetch) => apiFetch<ChatPayload>('/api/v1/chat', fetcher);
export const loadSettingsSchema = (fetcher: typeof fetch) =>
  apiFetch<SettingsSchemaPayload>('/api/v1/settings/schema', fetcher);
export const saveSettings = (updates: Record<string, string | number | boolean | null>) =>
  apiMutation<{ saved: boolean; changed: Record<string, unknown>; restart_required: string[]; actions: string[] }>(
    '/api/v1/settings',
    { updates }
  );

export const approveTag = (name: string) => apiMutation<ReviewMutationResponse>('/api/v1/tags/approve', { name });
export const rejectTag = (name: string) => apiMutation<ReviewMutationResponse>('/api/v1/tags/reject', { name });
export const unblacklistTag = (name: string) => apiMutation<ReviewMutationResponse>('/api/v1/tags/unblacklist', { name });
export const approveCorrespondent = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/correspondents/approve', { name });
export const rejectCorrespondent = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/correspondents/reject', { name });
export const unblacklistCorrespondent = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/correspondents/unblacklist', { name });
export const approveDoctype = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/doctypes/approve', { name });
export const rejectDoctype = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/doctypes/reject', { name });
export const unblacklistDoctype = (name: string) =>
  apiMutation<ReviewMutationResponse>('/api/v1/doctypes/unblacklist', { name });
export const startPoll = () => apiMutation<DashboardPayload['pipeline']>('/api/v1/jobs/poll/start', {});
export const cancelPoll = () => apiMutation<DashboardPayload['pipeline']>('/api/v1/jobs/poll/cancel', {});
export const startReindex = () => apiMutation<DashboardPayload['reindex']>('/api/v1/jobs/reindex/start', {});
export const cancelReindexJob = () => apiMutation<DashboardPayload['reindex']>('/api/v1/jobs/reindex/cancel', {});
