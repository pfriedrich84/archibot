import { expect, test } from '@playwright/test';

const dashboardPayload = {
  generated_at: '2026-04-26T00:00:00Z',
  kpis: {
    pending_review: 5,
    committed_today: 2,
    errors_24h: 1,
    pending_tags: 3,
    processed_documents: 42,
    embedded_documents: 40,
    inbox_pending: 7
  },
  status_counts: { pending: 5, committed: 2 },
  activity: {
    daily_commits: [{ day: '2026-04-26', count: 2 }],
    phase_health: { classify: { total: 10, errors: 1, avg_ms: 4200, error_rate_pct: 10 } }
  },
  pipeline: {
    running: false,
    phase: 'idle',
    done: 0,
    total: 0,
    succeeded: 0,
    failed: 0,
    skipped: 0,
    cancelled: false,
    error: null,
    started_at: null,
    next_run_at: '2026-04-26T12:00:00Z',
    last_poll: null
  },
  reindex: {
    running: false,
    done: 0,
    total: 0,
    failed: 0,
    cancelled: false,
    error: null
  },
  health: {
    setup_complete: true,
    embedding_index_ready: true,
    paperless_configured: true,
    ollama_configured: true,
    ocr_mode: 'vision_light',
    poll_interval_seconds: 300,
    auto_commit_confidence: 85
  },
  recent_errors: []
};

const statusPayload = {
  app: {
    name: 'ArchiBot',
    version: '0.1.0',
    setup_complete: true,
    legacy_ui: { active: true, deprecated: true, cutover_ready: false },
    frontend: { new_app_path: '/app', mode: 'migration', rendering: 'hybrid' }
  },
  services: {
    paperless: { configured: true, url: 'http://paperless.local' },
    ollama: {
      configured: true,
      url: 'http://ollama.local',
      model: 'gemma4:e4b',
      ocr_model: 'qwen3-vl:4b',
      embedding_model: 'qwen3-embedding:4b'
    }
  },
  jobs: { poll: dashboardPayload.pipeline, reindex: dashboardPayload.reindex },
  logging: { level: 'INFO', request_ids: true, structured_logs: true }
};

const settingsSchemaPayload = {
  setup_complete: true,
  categories: [
    {
      name: 'Paperless',
      fields: [
        {
          name: 'paperless_url',
          label: 'Paperless URL',
          input_type: 'text',
          required: true,
          restart: null,
          help: 'URL der Paperless-Instanz',
          sensitive: false,
          value: 'http://paperless.local',
          configured: null
        }
      ]
    }
  ]
};

const reviewPayload = {
  total: 1,
  items: [
    {
      id: 11,
      document_id: 77,
      created_at: '2026-04-26T09:00:00Z',
      status: 'pending',
      confidence: 92,
      proposed_title: 'Stromrechnung April',
      proposed_correspondent_name: 'Stadtwerke',
      proposed_doctype_name: 'Rechnung',
      proposed_storage_path_name: 'Finanzen/Strom',
      judge_verdict: 'agree',
      document_status: 'pending'
    }
  ]
};

const reviewDetailPayload = {
  suggestion: {
    id: 11,
    document_id: 77,
    created_at: '2026-04-26T09:00:00Z',
    status: 'pending',
    confidence: 92,
    reasoning: 'Invoice layout matched a known utility bill pattern.',
    judge_verdict: 'agree',
    judge_reasoning: null
  },
  original: {
    title: 'scan_2026_04.pdf',
    date: '2026-04-01',
    correspondent_id: null,
    correspondent_name: null,
    doctype_id: null,
    doctype_name: null,
    storage_path_id: null,
    storage_path_name: null,
    tags: []
  },
  proposed: {
    title: 'Stromrechnung April',
    date: '2026-04-01',
    correspondent_id: 1,
    correspondent_name: 'Stadtwerke',
    suggested_correspondent_name: null,
    doctype_id: 2,
    doctype_name: 'Rechnung',
    suggested_doctype_name: null,
    storage_path_id: 3,
    storage_path_name: 'Finanzen/Strom',
    suggested_storage_path_name: null,
    tags: [{ id: 5, name: 'Rechnung', confidence: 88 }]
  },
  options: {
    correspondents: [{ id: 1, name: 'Stadtwerke' }],
    doctypes: [{ id: 2, name: 'Rechnung' }],
    storage_paths: [{ id: 3, name: 'Finanzen/Strom' }],
    tags: [{ id: 5, name: 'Rechnung' }]
  },
  context_docs: [],
  original_proposal: null
};

const inboxPayload = {
  total: 2,
  counts: { pending: 1, committed: 1 },
  items: [
    {
      document_id: 77,
      status: 'pending',
      last_updated_at: '2026-04-26T09:00:00Z',
      last_processed: '2026-04-26T09:05:00Z',
      suggestion_id: 11,
      suggestion_status: 'pending',
      confidence: 92,
      proposed_title: 'Stromrechnung April',
      proposed_correspondent_name: 'Stadtwerke',
      proposed_doctype_name: 'Rechnung'
    }
  ]
};

const tagsPayload = {
  whitelist: [{ name: 'Rechnung', paperless_id: 5, approved: true, first_seen: '2026-04-20', times_seen: 8, notes: null }],
  blacklist: [{ name: 'Spam', rejected_at: '2026-04-21', times_seen: 2, notes: null }]
};

const errorsPayload = {
  items: [
    {
      id: 1,
      occurred_at: '2026-04-26T10:00:00Z',
      stage: 'ocr',
      document_id: 77,
      message: 'OCR failed',
      details: 'out of memory'
    }
  ]
};

const statsPayload = {
  totals: {
    processed_documents: 42,
    embedded_documents: 40,
    total_errors: 1,
    total_commits: 12,
    auto_commits: 3
  },
  status_counts: { pending: 5, committed: 12 },
  daily_commits: [{ day: '2026-04-26', count: 2 }],
  phase_health: { classify: { total: 10, errors: 1, avg_ms: 4200, error_rate_pct: 10 } },
  confidence_distribution: { '80-100': 8, '60-79': 2 },
  judge_counts: { agree: 7, corrected: 1 }
};

const embeddingsPayload = {
  total_embedded: 40,
  items: [
    {
      document_id: 77,
      title: 'Stromrechnung April',
      correspondent: 1,
      doctype: 2,
      storage_path: 3,
      created_date: '2026-04-01',
      indexed_at: '2026-04-26T10:00:00Z'
    }
  ]
};

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/dashboard', async (route) => {
    await route.fulfill({ json: dashboardPayload });
  });
  await page.route('**/api/v1/system/status', async (route) => {
    await route.fulfill({ json: statusPayload });
  });
  await page.route('**/api/v1/settings/schema', async (route) => {
    await route.fulfill({ json: settingsSchemaPayload });
  });
  await page.route('**/api/v1/review/queue', async (route) => {
    await route.fulfill({ json: reviewPayload });
  });
  await page.route('**/api/v1/review/11', async (route) => {
    await route.fulfill({ json: reviewDetailPayload });
  });
  await page.route('**/api/v1/inbox', async (route) => {
    await route.fulfill({ json: inboxPayload });
  });
  await page.route('**/api/v1/tags', async (route) => {
    await route.fulfill({ json: tagsPayload });
  });
  await page.route('**/api/v1/errors/recent', async (route) => {
    await route.fulfill({ json: errorsPayload });
  });
  await page.route('**/api/v1/stats', async (route) => {
    await route.fulfill({ json: statsPayload });
  });
  await page.route('**/api/v1/embeddings', async (route) => {
    await route.fulfill({ json: embeddingsPayload });
  });
});

test('dashboard renders hero metrics', async ({ page }) => {
  await page.goto('/app/');
  await expect(page.getByText('Pending Review')).toBeVisible();
  await expect(page.getByText('Errors (24h)')).toBeVisible();
  await expect(page.getByText('Flowbite Svelte', { exact: true })).toBeVisible();
});

test('settings route renders schema-driven category card', async ({ page }) => {
  await page.goto('/app/settings');
  await expect(page.getByRole('heading', { name: 'Einstellungen' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Paperless' })).toBeVisible();
  await expect(page.getByText('Paperless URL')).toBeVisible();
});

test('review route renders native inspector workflow', async ({ page }) => {
  await page.goto('/app/review');
  await expect(page.getByText('aktive Vorschläge')).toBeVisible();
  await expect(page.getByText('Stromrechnung April')).toBeVisible();
  await expect(page.getByText('Review Inspector')).toBeVisible();
  await expect(page.getByText('Accept & commit')).toBeVisible();
});

test('stats route renders phase health card', async ({ page }) => {
  await page.goto('/app/stats');
  await expect(page.getByText('Phase Health')).toBeVisible();
  await expect(page.getByText('classify')).toBeVisible();
  await expect(page.getByText('Confidence 80-100')).toBeVisible();
});
