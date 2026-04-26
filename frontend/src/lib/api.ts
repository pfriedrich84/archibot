import type {
  ChatPayload,
  DashboardPayload,
  EmbeddingsPayload,
  ErrorsPayload,
  InboxPayload,
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

export const loadDashboard = (fetcher: typeof fetch) => apiFetch<DashboardPayload>('/api/v1/dashboard', fetcher);
export const loadStatus = (fetcher: typeof fetch) => apiFetch<StatusPayload>('/api/v1/system/status', fetcher);
export const loadErrors = (fetcher: typeof fetch) => apiFetch<ErrorsPayload>('/api/v1/errors/recent', fetcher);
export const loadReviewQueue = (fetcher: typeof fetch) => apiFetch<ReviewQueuePayload>('/api/v1/review/queue', fetcher);
export const loadInbox = (fetcher: typeof fetch) => apiFetch<InboxPayload>('/api/v1/inbox', fetcher);
export const loadTags = (fetcher: typeof fetch) => apiFetch<TagsPayload>('/api/v1/tags', fetcher);
export const loadStats = (fetcher: typeof fetch) => apiFetch<StatsPayload>('/api/v1/stats', fetcher);
export const loadEmbeddings = (fetcher: typeof fetch) => apiFetch<EmbeddingsPayload>('/api/v1/embeddings', fetcher);
export const loadChat = (fetcher: typeof fetch) => apiFetch<ChatPayload>('/api/v1/chat', fetcher);
export const loadSettingsSchema = (fetcher: typeof fetch) =>
  apiFetch<SettingsSchemaPayload>('/api/v1/settings/schema', fetcher);
