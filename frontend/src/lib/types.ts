export type DashboardPayload = {
  generated_at: string;
  kpis: Record<string, number>;
  status_counts: Record<string, number>;
  activity: {
    daily_commits: Array<{ day: string; count: number }>;
    phase_health: Record<string, { total: number; errors: number; avg_ms: number; error_rate_pct: number }>;
  };
  pipeline: {
    running: boolean;
    phase: string;
    done: number;
    total: number;
    succeeded: number;
    failed: number;
    skipped: number;
    cancelled: boolean;
    error: string | null;
    started_at: string | null;
    next_run_at: string | null;
    last_poll: null | {
      started_at: string;
      finished_at: string;
      total_docs: number;
      succeeded: number;
      failed: number;
      skipped: number;
      relative_finished: string | null;
    };
  };
  reindex: {
    running: boolean;
    done: number;
    total: number;
    failed: number;
    cancelled: boolean;
    error: string | null;
  };
  health: {
    setup_complete: boolean;
    embedding_index_ready: boolean;
    paperless_configured: boolean;
    ollama_configured: boolean;
    ocr_mode: string;
    poll_interval_seconds: number;
    auto_commit_confidence: number;
  };
  recent_errors: Array<{
    id: number;
    occurred_at: string;
    stage: string;
    document_id: number | null;
    message: string;
    details: string | null;
  }>;
};

export type StatusPayload = {
  app: {
    name: string;
    version: string;
    setup_complete: boolean;
    legacy_ui: { active: boolean; deprecated: boolean; cutover_ready: boolean };
    frontend: { new_app_path: string; mode: string; rendering: string };
  };
  services: {
    paperless: { configured: boolean; url: string };
    ollama: { configured: boolean; url: string; model: string; ocr_model: string; embedding_model: string };
  };
  jobs: {
    poll: DashboardPayload['pipeline'];
    reindex: DashboardPayload['reindex'];
  };
  logging: { level: string; request_ids: boolean; structured_logs: boolean };
};

export type SettingsSchemaPayload = {
  categories: Array<{
    name: string;
    fields: Array<{
      name: string;
      label: string;
      input_type: string;
      required: boolean;
      restart: string | null;
      help: string;
      sensitive: boolean;
      value: string | number | boolean;
      configured: boolean | null;
    }>;
  }>;
  setup_complete: boolean;
};

export type ReviewQueuePayload = {
  total: number;
  items: Array<{
    id: number;
    document_id: number;
    created_at: string;
    status: string;
    confidence: number | null;
    proposed_title: string | null;
    proposed_correspondent_name: string | null;
    proposed_doctype_name: string | null;
    proposed_storage_path_name: string | null;
    judge_verdict: string | null;
    document_status: string | null;
  }>;
};

export type InboxPayload = {
  total: number;
  counts: Record<string, number>;
  items: Array<{
    document_id: number;
    status: string;
    last_updated_at: string;
    last_processed: string;
    suggestion_id: number | null;
    suggestion_status: string | null;
    confidence: number | null;
    proposed_title: string | null;
    proposed_correspondent_name: string | null;
    proposed_doctype_name: string | null;
  }>;
};

export type TagsPayload = {
  whitelist: Array<{
    name: string;
    paperless_id: number | null;
    approved: boolean;
    first_seen: string;
    times_seen: number;
    notes: string | null;
  }>;
  blacklist: Array<{
    name: string;
    rejected_at: string;
    times_seen: number;
    notes: string | null;
  }>;
};

export type ErrorsPayload = {
  items: Array<{
    id: number;
    occurred_at: string;
    stage: string;
    document_id: number | null;
    message: string;
    details: string | null;
  }>;
};

export type StatsPayload = {
  totals: {
    processed_documents: number;
    embedded_documents: number;
    total_errors: number;
    total_commits: number;
    auto_commits: number;
  };
  status_counts: Record<string, number>;
  daily_commits: Array<{ day: string; count: number }>;
  phase_health: Record<string, { total: number; errors: number; avg_ms: number; error_rate_pct: number }>;
  confidence_distribution: Record<string, number>;
  judge_counts: Record<string, number>;
};

export type EmbeddingsPayload = {
  total_embedded: number;
  items: Array<{
    document_id: number;
    title: string | null;
    correspondent: number | null;
    doctype: number | null;
    storage_path: number | null;
    created_date: string | null;
    indexed_at: string;
  }>;
};

export type ChatPayload = {
  recent_activity: Array<{
    details: string | null;
    occurred_at: string;
  }>;
};
