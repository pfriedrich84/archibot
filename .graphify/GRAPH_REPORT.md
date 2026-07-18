# Graph Report - .  (2026-07-18)

## Corpus Check
- Large corpus: 608 files · ~243,000 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder, or use --no-semantic to run AST-only.

## Summary
- 3324 nodes · 4692 edges · 308 communities detected
- Extraction: 89% EXTRACTED · 11% INFERRED · 0% AMBIGUOUS · INFERRED: 504 edges (avg confidence: 0.5)
- Token cost: 0 input · 0 output
- Edge kinds: method: 1215 · contains: 1197 · calls: 1158 · uses: 504 · rationale_for: 370 · reads_from: 112 · imports_from: 102 · inherits: 23 · imports: 9 · re_exports: 2


## Input Scope
- Requested: all
- Resolved: all (source: cli)
- Included files: 608 · Candidates: recursive
- Excluded: 0 untracked · 0 ignored · 9 sensitive · 0 missing committed

## Graph Freshness
- Built from Git commit: `f2f2149`
- Compare this hash to `git rev-parse HEAD` before trusting freshness-sensitive graph output.
## God Nodes (most connected - your core abstractions)
1. `PaperlessDocument` - 97 edges
2. `PaperlessClient` - 78 edges
3. `AiProviderGateway` - 60 edges
4. `PaperlessEntity` - 45 edges
5. `PipelineRecoveryDispatcher` - 44 edges
6. `ClassificationResult` - 43 edges
7. `PipelineRecoveryDispatcherTest` - 36 edges
8. `PaperlessEventWebhookTest` - 34 edges
9. `FirstRunSetupTest` - 33 edges
10. `ExecutionLifecycle` - 32 edges

## Surprising Connections (you probably didn't know these)
- `Event-driven review commit helpers.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Return accepted event-driven review suggestions that need commit.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Load fields needed to patch Paperless for one accepted suggestion.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Build safe Paperless PATCH fields from reviewed IDs only.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Patch Paperless for one accepted review suggestion.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py

## Communities

### Community 245 - "Community 245"
Cohesion: 0.67
Nodes (1): Import-graph guard fixture package.

### Community 8 - "Community 8"
Cohesion: 0.08
Nodes (25): queue_backend_name(), _QueuedTaskCallable, _AbsurdBackend, _worker_kwargs(), queue_name(), _normalize_absurd_database_url(), _resolved_absurd_database_url(), _configure_queue_backend() (+17 more)

### Community 21 - "Community 21"
Cohesion: 0.15
Nodes (25): _build_initial_embedding_index_impl(), _reconcile_inbox_documents_impl(), _reindex_ocr_documents_impl(), _commit_review_suggestion_impl(), _fail(), _exception_summary(), _exception_location(), _payload_limit() (+17 more)

### Community 41 - "Community 41"
Cohesion: 0.18
Nodes (18): ActorRunnerError, RuntimeError, _handle_document_pipeline_impl(), _handle_paperless_webhook_impl(), run_document_pipeline(), run_webhook_delivery(), _invocation(), Raised when a fixed actor command cannot be executed safely. (+10 more)

### Community 35 - "Community 35"
Cohesion: 0.14
Nodes (20): Run a review commit from durable command payload., CommandRecord, sql_text(), load_command(), _list_pending_commands(), list_pending_embedding_build_commands(), list_pending_poll_reconciliation_commands(), list_pending_reindex_commands() (+12 more)

### Community 303 - "Community 303"
Cohesion: 1.00
Nodes (1): Queue-backed actor package for the event-driven Archibot pipeline.

### Community 20 - "Community 20"
Cohesion: 0.13
Nodes (18): EntityCatalog, DocumentClassificationOutcome, run_async(), _fetch_paperless_document(), _load_entity_catalog(), _classify_document(), _update_item_derived_progress(), start_pipeline_item() (+10 more)

### Community 0 - "Community 0"
Cohesion: 0.10
Nodes (55): Document processing actors for the event-driven pipeline., Start/resume a document actor phase item by stable key.      Kept as a module-le, Handle one document pipeline run through durable event-driven steps.      The pr, Paperless-NGX REST API client., Return all documents tagged with the inbox tag, paginated., Apply metadata changes to a document., Download the original document file (PDF/image).          Returns ``(file_bytes,, Download Paperless' browser-friendly preview rendition.          Paperless expos (+47 more)

### Community 198 - "Community 198"
Cohesion: 1.00
Nodes (3): _coerce_limit(), _build_pgvector_embeddings(), _build_initial_embedding_index_impl()

### Community 1 - "Community 1"
Cohesion: 0.08
Nodes (31): Embedding actors and initial embedding-index build actors., Build the initial PostgreSQL/pgvector document embedding index., _fetch_inbox_documents(), _modified_value(), _reconcile_inbox_documents_impl(), _reindex_ocr_documents_impl(), Maintenance actors for polling reconciliation, recovery and reindex., Poll Paperless inbox as reconciliation and use the shared pipeline start. (+23 more)

### Community 199 - "Community 199"
Cohesion: 0.67
Nodes (2): run_async(), _commit_review_suggestion_impl()

### Community 2 - "Community 2"
Cohesion: 0.08
Nodes (27): Neutral AI-provider seam for ArchiBot runtime code., _strip_markdown_fences(), _exc_to_str(), AiProviderClient, _parse_chat_json_content(), _make_strict_json_retry_payload(), _make_strict_openai_json_retry_payload(), _is_context_length_error() (+19 more)

### Community 356 - "Community 356"
Cohesion: 1.00
Nodes (1): Harden a chat payload for JSON-recovery retries.          Used after malformed J

### Community 357 - "Community 357"
Cohesion: 1.00
Nodes (1): Check if a 500 response is caused by input exceeding the context length.

### Community 358 - "Community 358"
Cohesion: 1.00
Nodes (1): Exponential backoff with jitter for retry attempt ``attempt``.

### Community 359 - "Community 359"
Cohesion: 1.00
Nodes (1): Parse JSON content, handling occasional markdown fence wrappers.

### Community 92 - "Community 92"
Cohesion: 0.36
Nodes (10): _configure_logging(), _laravel_artisan_path(), _run_artisan(), cmd_laravel_maintenance(), cmd_reset(), cmd_commit_review(), _reject_unknown_args(), _positive_int() (+2 more)

### Community 6 - "Community 6"
Cohesion: 0.07
Nodes (26): require_postgresql_database_url(), Settings, BaseSettings, _apply_config_env_overrides(), assert_product_database_config(), _FieldMeta, dict, Application configuration via pydantic-settings (.env-driven). (+18 more)

### Community 361 - "Community 361"
Cohesion: 1.00
Nodes (1): Ignore every legacy confidence threshold under ADR-0018.

### Community 362 - "Community 362"
Cohesion: 1.00
Nodes (1): Treat empty env values for typed settings as unset.          Docker Compose/.env

### Community 363 - "Community 363"
Cohesion: 1.00
Nodes (1): Named AI provider profiles, always including the default profile.          `ai_p

### Community 364 - "Community 364"
Cohesion: 1.00
Nodes (1): Expected embedding vector dimension.          `ollama_embed_dim=0` enables auto

### Community 134 - "Community 134"
Cohesion: 0.38
Nodes (4): run_recovery_loop(), build_parser(), main(), Retired Absurd worker compatibility entry point.  Laravel database queues are th

### Community 307 - "Community 307"
Cohesion: 1.00
Nodes (1): Event helpers for the event-driven Archibot pipeline.

### Community 105 - "Community 105"
Cohesion: 0.31
Nodes (7): sql_text(), _payload_json(), _log_level_method(), publish_pipeline_event(), PostgreSQL-backed pipeline event publishing helpers., Return a structlog method name for a durable event level string., Publish a durable pipeline event and mirror it to structured logs.      Callers

### Community 308 - "Community 308"
Cohesion: 1.00
Nodes (1): Canonical event names for the event-driven pipeline.

### Community 14 - "Community 14"
Cohesion: 0.10
Nodes (21): FailureDisposition, source_fence(), protocol_failure(), sanitize_error(), transition_allowed(), start(), start_actor_execution(), finish_actor_execution() (+13 more)

### Community 309 - "Community 309"
Cohesion: 1.00
Nodes (1): Shared job helpers for Absurd actors.

### Community 53 - "Community 53"
Cohesion: 0.20
Nodes (16): _PostgresqlActorExecutionSql, StaleActorExecutionRecord, sql_text(), start_actor_execution(), _transition_source_for_execution(), _release_source_fence(), finish_actor_execution(), schedule_actor_execution_retry() (+8 more)

### Community 202 - "Community 202"
Cohesion: 0.50
Nodes (3): worker_id(), Runtime context helpers for actors., Return host, PID and Linux process-start identity for actor liveness checks.

### Community 203 - "Community 203"
Cohesion: 0.50
Nodes (3): engine(), Shared PostgreSQL connection helpers for durable job state., Return the shared product engine, which can only target PostgreSQL.

### Community 10 - "Community 10"
Cohesion: 0.11
Nodes (32): DocumentEmbeddingRow, sql_text(), document_embedding_text(), content_hash_for_text(), pgvector_literal(), _metadata_value(), _modified_value(), _tags_value() (+24 more)

### Community 135 - "Community 135"
Cohesion: 0.38
Nodes (6): sql_text(), latest_embedding_index_status(), ensure_embedding_index_ready(), Embedding readiness gate contract., Return the newest durable embedding-index status from PostgreSQL., Return whether document processing may start.      Document processing is allowe

### Community 79 - "Community 79"
Cohesion: 0.29
Nodes (11): EmbeddingIndexBuild, sql_text(), load_latest_embedding_index_build(), load_embedding_index_build(), start_embedding_index_build(), update_embedding_index_progress(), finish_embedding_index_build(), Durable embedding-index state helpers. (+3 more)

### Community 171 - "Community 171"
Cohesion: 0.50
Nodes (4): _text(), rejected_entity_names(), PostgreSQL repository for durable entity approval/blacklist state., Return rejected names from Laravel's shared entity approval table.

### Community 246 - "Community 246"
Cohesion: 0.67
Nodes (1): Idempotency helper for persisted webhook deliveries.

### Community 204 - "Community 204"
Cohesion: 0.50
Nodes (1): Lock key helpers for event-driven pipeline coordination.

### Community 116 - "Community 116"
Cohesion: 0.36
Nodes (7): OcrCorrection, _text(), store_ocr_correction(), cached_ocr_correction(), cached_ocr_document_ids(), PostgreSQL repository for local-only OCR corrections., Idempotently persist corrected OCR text in the shared schema.

### Community 75 - "Community 75"
Cohesion: 0.21
Nodes (12): _psycopg_database_url(), _add_cleanup_note(), _lease(), document_actor_lease(), embedding_mutation_lease(), embedding_index_ready(), PostgreSQL advisory leases shared by productive Python pipeline actors.  The lea, Attach a secondary cleanup failure without replacing the primary error. (+4 more)

### Community 93 - "Community 93"
Cohesion: 0.25
Nodes (10): sql_text(), start_pipeline_item(), start_or_resume_pipeline_item(), finish_pipeline_item(), progress_from_pipeline_items(), Durable pipeline item helpers., Create a running item row for a retry-safe pipeline step., Start or resume a phase item identified by a stable per-run key. (+2 more)

### Community 25 - "Community 25"
Cohesion: 0.12
Nodes (23): DocumentPipelineRunRecord, sql_text(), load_document_pipeline_run(), list_embedding_blocked_pipeline_run_ids(), list_cancel_requested_pipeline_run_ids(), is_pipeline_run_cancel_requested(), mark_pipeline_run_cancelled(), list_pending_document_pipeline_run_ids() (+15 more)

### Community 172 - "Community 172"
Cohesion: 0.50
Nodes (4): PollCandidateResult, persist_poll_candidate(), Durable discovery handoff from Python polling to Laravel Pipeline Start., Persist one idempotent protocol-v1 candidate; never create a Pipeline Run.

### Community 136 - "Community 136"
Cohesion: 0.38
Nodes (6): sql_text(), update_pipeline_run_progress(), update_actor_execution_progress(), Durable progress helper contracts.  Progress must be stored in PostgreSQL and de, Persist a pipeline-level progress snapshot., Persist an actor-level progress snapshot.

### Community 67 - "Community 67"
Cohesion: 0.13
Nodes (1): Compatibility facade for Python recovery transitions.  Productive redispatch thr

### Community 117 - "Community 117"
Cohesion: 0.25
Nodes (7): retry_backoff_seconds(), should_retry(), classify_exception(), Retry classification contracts for event-driven actors., Return the bounded default backoff for a 1-based retry attempt., Return whether an actor should schedule another durable attempt., Classify common actor exceptions without logging sensitive payloads.      The cl

### Community 72 - "Community 72"
Cohesion: 0.21
Nodes (13): ReviewCommitRecord, sql_text(), list_review_suggestions_ready_to_commit(), load_review_commit(), _optional_int(), build_paperless_patch(), commit_review_suggestion_to_paperless(), mark_review_commit_status() (+5 more)

### Community 80 - "Community 80"
Cohesion: 0.33
Nodes (10): StoredReviewSuggestion, sql_text(), _json(), _safe_context_documents(), _entity_id(), _proposed_tags(), _raw_payload(), _upsert_entity_approval() (+2 more)

### Community 94 - "Community 94"
Cohesion: 0.20
Nodes (10): WebhookDeliveryRecord, load_webhook_delivery(), list_queued_webhook_delivery_ids(), list_embedding_blocked_webhook_delivery_ids(), mark_webhook_delivery_status(), PostgreSQL helpers for webhook delivery actor state., Load the normalized state needed by the webhook Absurd actor., Return queued webhook deliveries eligible for actor enqueue/recovery. (+2 more)

### Community 12 - "Community 12"
Cohesion: 0.08
Nodes (29): _configure_logging(), lifespan(), Permission-scoped MCP server with no local product-state backend., Start without a product-state backend or privileged global Paperless client., McpIdentity, from_laravel_payload(), RateLimiter, _extract_token() (+21 more)

### Community 205 - "Community 205"
Cohesion: 0.50
Nodes (3): register(), Classification retired until a permission-aware Laravel Pipeline seam is availab, Intentionally register nothing; see the MCP disposition matrix.

### Community 206 - "Community 206"
Cohesion: 0.50
Nodes (3): register(), Correspondent proposal operations remain retired pending an authorized PostgreSQ, Intentionally register nothing; see the MCP disposition matrix.

### Community 207 - "Community 207"
Cohesion: 0.50
Nodes (3): register(), Document-type proposal operations remain retired pending an authorized PostgreSQ, Intentionally register nothing; see the MCP disposition matrix.

### Community 208 - "Community 208"
Cohesion: 0.50
Nodes (3): register(), Document registrations retired pending a permission-aware Laravel/PostgreSQL sea, Intentionally register nothing; see the MCP disposition matrix.

### Community 209 - "Community 209"
Cohesion: 0.50
Nodes (3): register(), Entity registrations retired pending a permission-aware Laravel/PostgreSQL seam., Intentionally register nothing; see the MCP disposition matrix.

### Community 210 - "Community 210"
Cohesion: 0.50
Nodes (3): register(), Identity-less FastMCP resources retired because they cannot enforce per-user per, Intentionally register nothing; see the MCP disposition matrix.

### Community 211 - "Community 211"
Cohesion: 0.50
Nodes (3): register(), Suggestion reads and decisions retired until the Laravel Review seam is exposed, Intentionally register nothing; see the MCP disposition matrix.

### Community 212 - "Community 212"
Cohesion: 0.50
Nodes (3): register(), System status retired because diagnostics require an admin-only PostgreSQL redac, Intentionally register nothing; see the MCP disposition matrix.

### Community 213 - "Community 213"
Cohesion: 0.50
Nodes (3): register(), Tag proposal operations remain retired pending an authorized PostgreSQL seam., Intentionally register nothing; see the MCP disposition matrix.

### Community 31 - "Community 31"
Cohesion: 0.13
Nodes (16): _coerce_optional_entity_id(), _coerce_entity_id_list(), BaseModel, _coerce_entity_id(), _coerce_tags(), ProposedTag, TagWhitelistEntry, TagBlacklistEntry (+8 more)

### Community 366 - "Community 366"
Cohesion: 1.00
Nodes (1): Accept common loose tag outputs from LLMs.          Normal form is a list of obj

### Community 32 - "Community 32"
Cohesion: 0.20
Nodes (21): _load_system_prompt(), _load_judge_system_prompt(), _truncate(), _estimate_tokens(), _tokens_to_chars(), _format_document_block(), _resolve_entity_name(), _format_context_block() (+13 more)

### Community 60 - "Community 60"
Cohesion: 0.17
Nodes (15): store_embedding(), index_document(), find_similar_with_precomputed_embedding(), find_similar_with_distances(), find_similar_documents(), _load_similar(), find_similar_by_query_text_filtered(), find_similar_by_query_text() (+7 more)

### Community 3 - "Community 3"
Cohesion: 0.08
Nodes (36): maybe_run_judge(), _sanitize_ocr_text(), _parse_ocr_response(), ocr_requested_tag_id(), configured_ocr_tag_exists(), should_run_ocr_for_document(), effective_ocr_mode(), maybe_correct_ocr() (+28 more)

### Community 81 - "Community 81"
Cohesion: 0.26
Nodes (11): render_document_pages(), _is_pdf(), _is_image(), _render_pdf_pages(), _render_image(), page_count(), Render document files (PDF/image) to base64-encoded images for vision models., Convert a document file to a list of base64-encoded JPEG images.      For PDFs, (+3 more)

### Community 137 - "Community 137"
Cohesion: 0.33
Nodes (6): trusted_context_scope(), _tag_id(), is_trusted_document(), Trusted Document rules for classification context., Return the durable scope name for Trusted Document context., Return whether a Paperless Document is trusted classification context.      Doma

### Community 82 - "Community 82"
Cohesion: 0.32
Nodes (11): PromptSpec, get_prompt_spec(), default_prompt_path(), override_prompt_path(), load_default_prompt(), load_prompt(), validate_prompt(), save_prompt() (+3 more)

### Community 152 - "Community 152"
Cohesion: 0.33
Nodes (5): escape_html(), encode_path_segment(), Helpers for safely rendering small HTML fragments and user-visible errors., Escape a value for safe insertion into inline HTML fragments., Encode a value for use in URLs / DOM ids derived from path segments.

### Community 247 - "Community 247"
Cohesion: 0.67
Nodes (1): ArchibotReset

### Community 248 - "Community 248"
Cohesion: 0.67
Nodes (1): CommitReviewSuggestion

### Community 138 - "Community 138"
Cohesion: 0.48
Nodes (1): DispatchMaintenanceCommand

### Community 249 - "Community 249"
Cohesion: 0.67
Nodes (1): PruneAuditLogs

### Community 250 - "Community 250"
Cohesion: 0.67
Nodes (1): RecoverPipelineActors

### Community 251 - "Community 251"
Cohesion: 0.67
Nodes (1): ResetSetup

### Community 252 - "Community 252"
Cohesion: 0.67
Nodes (1): SchedulePollReconciliation

### Community 170 - "Community 170"
Cohesion: 0.50
Nodes (1): AuditLogController

### Community 104 - "Community 104"
Cohesion: 0.31
Nodes (1): MaintenanceController

### Community 52 - "Community 52"
Cohesion: 0.26
Nodes (1): SettingsController

### Community 313 - "Community 313"
Cohesion: 1.00
Nodes (1): Controller

### Community 139 - "Community 139"
Cohesion: 0.48
Nodes (1): DashboardController

### Community 175 - "Community 175"
Cohesion: 0.50
Nodes (1): EmbeddingIndexController

### Community 214 - "Community 214"
Cohesion: 0.50
Nodes (1): EmbeddingsController

### Community 83 - "Community 83"
Cohesion: 0.39
Nodes (1): EntityApprovalController

### Community 176 - "Community 176"
Cohesion: 0.50
Nodes (1): ErrorsController

### Community 140 - "Community 140"
Cohesion: 0.48
Nodes (1): HealthCheckController

### Community 141 - "Community 141"
Cohesion: 0.48
Nodes (1): InboxController

### Community 215 - "Community 215"
Cohesion: 0.50
Nodes (1): MaintenanceCommandController

### Community 84 - "Community 84"
Cohesion: 0.30
Nodes (1): OcrReviewController

### Community 216 - "Community 216"
Cohesion: 0.50
Nodes (1): OperationsLogController

### Community 61 - "Community 61"
Cohesion: 0.23
Nodes (1): PaperlessEventWebhookController

### Community 85 - "Community 85"
Cohesion: 0.29
Nodes (1): PipelineRunController

### Community 26 - "Community 26"
Cohesion: 0.17
Nodes (1): ReviewSuggestionController

### Community 177 - "Community 177"
Cohesion: 0.40
Nodes (1): SetupController

### Community 106 - "Community 106"
Cohesion: 0.36
Nodes (1): StatsController

### Community 96 - "Community 96"
Cohesion: 0.35
Nodes (1): WebhookDeliveryController

### Community 223 - "Community 223"
Cohesion: 0.67
Nodes (1): EnsureSetupIsComplete

### Community 260 - "Community 260"
Cohesion: 0.67
Nodes (1): EnsureUserIsAdmin

### Community 261 - "Community 261"
Cohesion: 0.67
Nodes (1): HandleAppearance

### Community 224 - "Community 224"
Cohesion: 0.50
Nodes (1): HandleInertiaRequests

### Community 119 - "Community 119"
Cohesion: 0.36
Nodes (1): ValidatePaperlessWebhookRequest

### Community 222 - "Community 222"
Cohesion: 0.50
Nodes (1): ApplyEntityApprovalCommand

### Community 68 - "Community 68"
Cohesion: 0.18
Nodes (1): RunPythonActorJob

### Community 155 - "Community 155"
Cohesion: 0.33
Nodes (1): ActorExecution

### Community 225 - "Community 225"
Cohesion: 0.50
Nodes (1): AppSetting

### Community 226 - "Community 226"
Cohesion: 0.50
Nodes (1): AuditLog

### Community 227 - "Community 227"
Cohesion: 0.50
Nodes (1): ChatMessage

### Community 156 - "Community 156"
Cohesion: 0.33
Nodes (1): ChatSession

### Community 228 - "Community 228"
Cohesion: 0.50
Nodes (1): Command

### Community 262 - "Community 262"
Cohesion: 0.67
Nodes (1): DocumentEmbedding

### Community 263 - "Community 263"
Cohesion: 0.67
Nodes (1): EmbeddingIndexState

### Community 157 - "Community 157"
Cohesion: 0.33
Nodes (1): EntityApproval

### Community 317 - "Community 317"
Cohesion: 1.00
Nodes (1): LlmCall

### Community 182 - "Community 182"
Cohesion: 0.40
Nodes (1): OcrReview

### Community 183 - "Community 183"
Cohesion: 0.40
Nodes (1): PipelineEvent

### Community 264 - "Community 264"
Cohesion: 0.67
Nodes (1): PipelineItem

### Community 143 - "Community 143"
Cohesion: 0.29
Nodes (1): PipelineRun

### Community 184 - "Community 184"
Cohesion: 0.40
Nodes (1): PollCandidate

### Community 99 - "Community 99"
Cohesion: 0.18
Nodes (1): ReviewSuggestion

### Community 158 - "Community 158"
Cohesion: 0.33
Nodes (1): SetupState

### Community 185 - "Community 185"
Cohesion: 0.40
Nodes (1): User

### Community 229 - "Community 229"
Cohesion: 0.50
Nodes (1): WebhookDelivery

### Community 159 - "Community 159"
Cohesion: 0.47
Nodes (1): AppServiceProvider

### Community 120 - "Community 120"
Cohesion: 0.39
Nodes (1): FortifyServiceProvider

### Community 243 - "Community 243"
Cohesion: 0.67
Nodes (1): ActorInvocationClaim

### Community 197 - "Community 197"
Cohesion: 0.50
Nodes (1): ActorInvocationClaimer

### Community 115 - "Community 115"
Cohesion: 0.29
Nodes (1): PythonActorOutcome

### Community 70 - "Community 70"
Cohesion: 0.31
Nodes (1): PythonActorRunner

### Community 186 - "Community 186"
Cohesion: 0.60
Nodes (1): ArchibotResetService

### Community 54 - "Community 54"
Cohesion: 0.24
Nodes (1): EntityApprovalDecisionService

### Community 221 - "Community 221"
Cohesion: 0.50
Nodes (1): ResponseSizeGuard

### Community 187 - "Community 187"
Cohesion: 0.50
Nodes (1): OllamaClient

### Community 160 - "Community 160"
Cohesion: 0.67
Nodes (1): CanonicalPaperlessOrigin

### Community 15 - "Community 15"
Cohesion: 0.12
Nodes (1): PaperlessClient

### Community 144 - "Community 144"
Cohesion: 0.48
Nodes (1): PaperlessDocumentPermissions

### Community 318 - "Community 318"
Cohesion: 1.00
Nodes (1): PaperlessUnavailableException

### Community 188 - "Community 188"
Cohesion: 0.40
Nodes (1): PaperlessUser

### Community 86 - "Community 86"
Cohesion: 0.29
Nodes (1): DocumentPipelineStarter

### Community 62 - "Community 62"
Cohesion: 0.33
Nodes (1): MaintenanceCommandDispatcher

### Community 189 - "Community 189"
Cohesion: 0.40
Nodes (1): PipelineContentStateNormalizer

### Community 230 - "Community 230"
Cohesion: 0.50
Nodes (1): PipelineLifecycleRecorder

### Community 4 - "Community 4"
Cohesion: 0.11
Nodes (1): PipelineRecoveryDispatcher

### Community 121 - "Community 121"
Cohesion: 0.39
Nodes (1): PipelineStartGate

### Community 265 - "Community 265"
Cohesion: 0.67
Nodes (1): PipelineStartResult

### Community 73 - "Community 73"
Cohesion: 0.29
Nodes (1): PollCandidateConsumer

### Community 266 - "Community 266"
Cohesion: 0.67
Nodes (1): PollCandidateLease

### Community 231 - "Community 231"
Cohesion: 0.67
Nodes (1): LegacySettingsImporter

### Community 122 - "Community 122"
Cohesion: 0.43
Nodes (1): PythonRuntimeConfigExporter

### Community 190 - "Community 190"
Cohesion: 0.50
Nodes (1): SettingsCatalog

### Community 267 - "Community 267"
Cohesion: 0.67
Nodes (1): CompleteSetup

### Community 109 - "Community 109"
Cohesion: 0.39
Nodes (1): PaperlessWebhookNormalizer

### Community 103 - "Community 103"
Cohesion: 0.33
Nodes (1): ActiveOperationsSnapshot

### Community 191 - "Community 191"
Cohesion: 0.40
Nodes (1): BuildInfo

### Community 19 - "Community 19"
Cohesion: 0.15
Nodes (1): DiagnosticPresenter

### Community 232 - "Community 232"
Cohesion: 0.67
Nodes (1): EmbeddingIndexSnapshot

### Community 145 - "Community 145"
Cohesion: 0.29
Nodes (1): OperatorPrincipal

### Community 255 - "Community 255"
Cohesion: 0.67
Nodes (1): EntityApprovalFactory

### Community 256 - "Community 256"
Cohesion: 0.67
Nodes (1): ReviewSuggestionFactory

### Community 178 - "Community 178"
Cohesion: 0.40
Nodes (1): UserFactory

### Community 123 - "Community 123"
Cohesion: 0.50
Nodes (7): up(), down(), decodeJson(), eventType(), webhookAction(), containsAny(), updateNormalizedPayload()

### Community 294 - "Community 294"
Cohesion: 0.67
Nodes (1): DatabaseSeeder

### Community 29 - "Community 29"
Cohesion: 0.12
Nodes (14): absurd.current_time, a, absurd.claim_task(), candidate, updated, absurd.set_task_checkpoint_state(), v_new_attempt, v_existing_owner (+6 more)

### Community 77 - "Community 77"
Cohesion: 0.17
Nodes (13): absurd.queues, absurd.ensure_queue_tables(), v_storage_mode, absurd.create_queue(), v_existing_mode, absurd.spawn_task, v_existing_task_id, absurd.retry_task() (+5 more)

### Community 24 - "Community 24"
Cohesion: 0.12
Nodes (25): absurd.drop_queue(), v_existing_queue, pg_proc, pg_namespace, pg_inherits, pg_class, absurd.list_detach_candidates, v_parent_oid (+17 more)

### Community 162 - "Community 162"
Cohesion: 0.33
Nodes (6): absurd.set_queue_policy(), v_unknown_key, jsonb_object_keys, v_exists, v_default_attached, v_default_has_rows

### Community 56 - "Community 56"
Cohesion: 0.14
Nodes (17): the, absurd, absurd.get_task_result(), absurd.complete_run(), v_task_id, absurd.schedule_run(), absurd.fail_run(), v_retry_strategy (+9 more)

### Community 149 - "Community 149"
Cohesion: 0.33
Nodes (7): absurd.cleanup_tasks(), eligible_tasks, to_delete, del_tasks, v_deleted_count, absurd.cleanup_events(), del_events

### Community 295 - "Community 295"
Cohesion: 0.67
Nodes (2): controlStatements, paddingAroundControl

### Community 9 - "Community 9"
Cohesion: 0.08
Nodes (25): initializeFlashToast(), ThemeState, appearance, prefersDark(), isDarkMode(), getResolvedAppearance(), setCookie(), applyTheme() (+17 more)

### Community 253 - "Community 253"
Cohesion: 0.67
Nodes (2): DialogContext, DIALOG_CONTEXT

### Community 254 - "Community 254"
Cohesion: 0.67
Nodes (2): DropdownMenuContext, DROPDOWN_MENU_CONTEXT

### Community 180 - "Community 180"
Cohesion: 0.40
Nodes (2): INPUT_OTP_CONTEXT, InputOTPContext

### Community 100 - "Community 100"
Cohesion: 0.22
Nodes (3): CurrentUrlState, cn(), toUrl()

### Community 296 - "Community 296"
Cohesion: 0.67
Nodes (2): SheetContext, SHEET_CONTEXT

### Community 45 - "Community 45"
Cohesion: 0.13
Nodes (3): SidebarContext, SIDEBAR_CONTEXT, useSidebar()

### Community 55 - "Community 55"
Cohesion: 0.21
Nodes (14): DisplayPreferences, preferences(), userTimezone(), userFormat(), partsFor(), isIsoDateTime(), formatDate(), formatDateTime() (+6 more)

### Community 297 - "Community 297"
Cohesion: 0.67
Nodes (1): InitialsApi

### Community 236 - "Community 236"
Cohesion: 0.67
Nodes (3): PaperlessEntityOption, numericId(), paperlessLabel()

### Community 237 - "Community 237"
Cohesion: 0.50
Nodes (1): lang

### Community 192 - "Community 192"
Cohesion: 0.40
Nodes (1): lang

### Community 339 - "Community 339"
Cohesion: 1.00
Nodes (1): lang

### Community 304 - "Community 304"
Cohesion: 1.00
Nodes (1): lang

### Community 305 - "Community 305"
Cohesion: 1.00
Nodes (1): lang

### Community 201 - "Community 201"
Cohesion: 0.50
Nodes (1): lang

### Community 310 - "Community 310"
Cohesion: 1.00
Nodes (1): lang

### Community 314 - "Community 314"
Cohesion: 1.00
Nodes (1): lang

### Community 315 - "Community 315"
Cohesion: 1.00
Nodes (1): lang

### Community 316 - "Community 316"
Cohesion: 1.00
Nodes (1): lang

### Community 329 - "Community 329"
Cohesion: 1.00
Nodes (1): lang

### Community 330 - "Community 330"
Cohesion: 1.00
Nodes (1): lang

### Community 331 - "Community 331"
Cohesion: 1.00
Nodes (1): lang

### Community 332 - "Community 332"
Cohesion: 1.00
Nodes (1): lang

### Community 333 - "Community 333"
Cohesion: 1.00
Nodes (1): lang

### Community 334 - "Community 334"
Cohesion: 1.00
Nodes (1): lang

### Community 335 - "Community 335"
Cohesion: 1.00
Nodes (1): lang

### Community 336 - "Community 336"
Cohesion: 1.00
Nodes (1): lang

### Community 337 - "Community 337"
Cohesion: 1.00
Nodes (1): lang

### Community 338 - "Community 338"
Cohesion: 1.00
Nodes (1): lang

### Community 257 - "Community 257"
Cohesion: 0.67
Nodes (1): ActiveOperationsSnapshotTest

### Community 40 - "Community 40"
Cohesion: 0.10
Nodes (1): AdminSettingsTest

### Community 200 - "Community 200"
Cohesion: 0.50
Nodes (1): AuditLogsTest

### Community 244 - "Community 244"
Cohesion: 0.67
Nodes (1): AuditPruneCommandTest

### Community 133 - "Community 133"
Cohesion: 0.29
Nodes (1): MaintenanceCliUiEquivalenceTest

### Community 71 - "Community 71"
Cohesion: 0.14
Nodes (1): MaintenanceTest

### Community 95 - "Community 95"
Cohesion: 0.27
Nodes (1): AuthenticationTest

### Community 173 - "Community 173"
Cohesion: 0.40
Nodes (1): LocalAccountManagementDisabledTest

### Community 153 - "Community 153"
Cohesion: 0.47
Nodes (1): ChatTest

### Community 258 - "Community 258"
Cohesion: 0.67
Nodes (1): DashboardTest

### Community 102 - "Community 102"
Cohesion: 0.20
Nodes (1): DiagnosticAuthorizationTest

### Community 118 - "Community 118"
Cohesion: 0.25
Nodes (1): EmbeddingIndexControlTest

### Community 28 - "Community 28"
Cohesion: 0.14
Nodes (1): EntityApprovalTest

### Community 259 - "Community 259"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 217 - "Community 217"
Cohesion: 0.50
Nodes (1): HealthCheckTest

### Community 154 - "Community 154"
Cohesion: 0.33
Nodes (1): InboxTest

### Community 107 - "Community 107"
Cohesion: 0.22
Nodes (1): MaintenanceCommandTest

### Community 27 - "Community 27"
Cohesion: 0.17
Nodes (1): OcrReviewTest

### Community 69 - "Community 69"
Cohesion: 0.15
Nodes (1): DocumentPipelineStartServiceTest

### Community 7 - "Community 7"
Cohesion: 0.11
Nodes (1): PipelineRecoveryDispatcherTest

### Community 76 - "Community 76"
Cohesion: 0.38
Nodes (1): PollCandidateConsumerTest

### Community 127 - "Community 127"
Cohesion: 0.32
Nodes (1): PostgresActorFencingUpgradeTest

### Community 147 - "Community 147"
Cohesion: 0.57
Nodes (1): PostgresPipelineFenceTest

### Community 128 - "Community 128"
Cohesion: 0.46
Nodes (1): PostgresWebhookPollConcurrencyTest

### Community 129 - "Community 129"
Cohesion: 0.29
Nodes (1): PythonActorSubprocessMatrixTest

### Community 36 - "Community 36"
Cohesion: 0.17
Nodes (1): RunPythonActorJobTest

### Community 97 - "Community 97"
Cohesion: 0.18
Nodes (1): PipelineRunControlTest

### Community 218 - "Community 218"
Cohesion: 0.50
Nodes (1): PipelineRunVisibilityTest

### Community 111 - "Community 111"
Cohesion: 0.22
Nodes (1): EmbeddingsTest

### Community 219 - "Community 219"
Cohesion: 0.50
Nodes (1): QueueConfigurationTest

### Community 193 - "Community 193"
Cohesion: 0.40
Nodes (1): ReviewCliEquivalenceTest

### Community 16 - "Community 16"
Cohesion: 0.06
Nodes (1): ReviewSuggestionTest

### Community 108 - "Community 108"
Cohesion: 0.22
Nodes (1): ScheduledPollReconciliationTest

### Community 298 - "Community 298"
Cohesion: 0.67
Nodes (1): LegacySettingsImportTest

### Community 13 - "Community 13"
Cohesion: 0.09
Nodes (1): FirstRunSetupTest

### Community 179 - "Community 179"
Cohesion: 0.40
Nodes (1): StatsAndErrorsTest

### Community 98 - "Community 98"
Cohesion: 0.31
Nodes (1): WebhookDeliveryControlTest

### Community 11 - "Community 11"
Cohesion: 0.09
Nodes (1): PaperlessEventWebhookTest

### Community 238 - "Community 238"
Cohesion: 0.50
Nodes (1): PaperlessWebhookTest

### Community 220 - "Community 220"
Cohesion: 0.50
Nodes (1): SQLiteActorExecutionSql

### Community 44 - "Community 44"
Cohesion: 0.27
Nodes (1): PostgresCliUiTerminalEquivalenceTest

### Community 181 - "Community 181"
Cohesion: 0.50
Nodes (1): PostgresEntityDecisionConcurrencyTest

### Community 161 - "Community 161"
Cohesion: 0.47
Nodes (1): TestCase

### Community 88 - "Community 88"
Cohesion: 0.17
Nodes (1): DiagnosticPresenterTest

### Community 299 - "Community 299"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 194 - "Community 194"
Cohesion: 0.40
Nodes (1): PaperlessWebhookNormalizerTest

### Community 130 - "Community 130"
Cohesion: 0.25
Nodes (1): PythonActorOutcomeTest

### Community 89 - "Community 89"
Cohesion: 0.24
Nodes (11): load_allowlist(), get_installed_packages(), get_release_date(), _parse_version(), check_cve_fix(), main(), Load package==version pairs that are exempted from the age check., Return [(name, version), ...] from pip freeze. (+3 more)

### Community 148 - "Community 148"
Cohesion: 0.52
Nodes (6): _read_text(), check_expected_files(), check_content_patterns(), _portable_check_command(), check_graphify_portability(), main()

### Community 239 - "Community 239"
Cohesion: 0.83
Nodes (3): iter_markdown_files(), is_external(), main()

### Community 22 - "Community 22"
Cohesion: 0.14
Nodes (25): Violation, productive_files(), _line(), legacy_reference_fingerprints(), load_legacy_fingerprint_baseline(), _string_fragment(), _python_name(), _dotted_name() (+17 more)

### Community 63 - "Community 63"
Cohesion: 0.13
Nodes (11): sample_entities(), sample_context_doc(), sample_doc(), mock_paperless(), mock_ollama(), Shared fixtures for the test suite., A small set of entities for resolution tests., A classified document suitable as context (not in inbox). (+3 more)

### Community 341 - "Community 341"
Cohesion: 1.00
Nodes (1): Nested legacy storage fixture module.

### Community 300 - "Community 300"
Cohesion: 0.67
Nodes (1): Optional live smoke test for the PostgreSQL-backed Absurd queue.  Run with a mig

### Community 33 - "Community 33"
Cohesion: 0.13
Nodes (8): FakeResult, FakeConnection, FakeRows, FakeEngine, test_start_actor_execution_inserts_running_row(), test_finish_actor_execution_updates_status(), test_schedule_actor_execution_retry_updates_retry_metadata(), test_list_stale_running_actor_executions_returns_records()

### Community 30 - "Community 30"
Cohesion: 0.12
Nodes (9): fenced(), test_every_actor_family_has_fixed_protocol_identity(), test_every_actor_family_real_subprocess_emits_protocol_failure_on_bootstrap_failure(), test_main_build_embedding_index_invokes_command(), test_main_process_document_invokes_pipeline_run(), test_main_handle_webhook_invokes_delivery(), test_main_commit_review_invokes_command(), test_main_reconcile_poll_invokes_command() (+1 more)

### Community 240 - "Community 240"
Cohesion: 0.50
Nodes (1): Tests for the neutral AI-provider seam.

### Community 5 - "Community 5"
Cohesion: 0.06
Nodes (15): _postgres_blacklist_repository(), TestResolveEntityName, TestFormatContextBlock, TestFormatDocumentBlock, TestBuildUserPrompt, TestNormalizationHelpers, TestPromptBudget, Tests for the classifier prompt builder and entity resolution. (+7 more)

### Community 47 - "Community 47"
Cohesion: 0.14
Nodes (11): _postgres_blacklist_repository(), _initial_result(), TestParseJudgeVerdict, TestBuildJudgeUserPrompt, TestVerify, test_verify_agree_roundtrip(), test_verify_corrected_returns_new_result(), test_verify_transport_error_becomes_error_verdict() (+3 more)

### Community 427 - "Community 427"
Cohesion: 1.00
Nodes (1): Ollama failures must not raise — caller keeps the initial result.

### Community 112 - "Community 112"
Cohesion: 0.22
Nodes (1): The operator CLI must not double as a productive Python actor contract.

### Community 37 - "Community 37"
Cohesion: 0.14
Nodes (9): FakeRows, FakeConnection, FakeEngine, test_load_command_returns_command_record(), test_load_command_returns_none_when_missing(), test_list_pending_embedding_build_commands_returns_payload(), test_list_pending_poll_reconciliation_commands_returns_payload(), test_list_pending_reindex_commands_returns_payload() (+1 more)

### Community 38 - "Community 38"
Cohesion: 0.10
Nodes (3): TestEnvFileIO, TestSaveConfig, Tests for config_writer — env file I/O and save_config logic.

### Community 150 - "Community 150"
Cohesion: 0.29
Nodes (1): Tests for pgvector-backed context builder compatibility facade.

### Community 151 - "Community 151"
Cohesion: 0.33
Nodes (3): _run_module_with_database_url(), test_product_entry_points_fail_closed_for_sqlite_database_url(), PostgreSQL-only product startup and engine regression tests.

### Community 42 - "Community 42"
Cohesion: 0.12
Nodes (5): FakeResult, FakeConnection, FakeEngine, test_store_document_embedding_persists_pgvector_metadata(), test_find_similar_document_ids_uses_pgvector_trusted_filters()

### Community 64 - "Community 64"
Cohesion: 0.17
Nodes (6): FakeResult, FakeConnection, FakeEngine, test_embedding_gate_allows_only_complete_status(), test_embedding_gate_fails_closed_without_state(), test_embedding_gate_fails_closed_for_incomplete_status()

### Community 57 - "Community 57"
Cohesion: 0.16
Nodes (7): FakeResult, FakeConnection, FakeEngine, test_start_embedding_index_build_creates_building_state(), test_start_embedding_index_build_returns_existing_build(), test_update_embedding_index_progress_persists_counts(), test_finish_embedding_index_build_updates_status()

### Community 43 - "Community 43"
Cohesion: 0.12
Nodes (4): Result, Connection, Engine, test_restart_reconstructs_versioned_outcome()

### Community 164 - "Community 164"
Cohesion: 0.60
Nodes (5): _actor(), test_poll_reconciliation_persists_marked_and_unmarked_candidates(), test_forced_poll_bypasses_marker_and_persists_force_metadata(), test_poll_reconciliation_schedules_retry_for_transient_fetch_failure(), test_poll_reconciliation_skips_without_inbox_tag()

### Community 74 - "Community 74"
Cohesion: 0.21
Nodes (9): CapturingMcp, make_ctx(), test_verified_identity_requires_laravel_and_complete_paperless_context(), test_verified_identity_rejects_revoked_token(), test_verified_identity_rejects_disabled_laravel_auth(), test_verified_identity_rejects_incomplete_paperless_context(), test_read_only_identity_cannot_use_write_guard(), test_every_baseline_module_is_retired_until_laravel_postgres_seams_exist() (+1 more)

### Community 23 - "Community 23"
Cohesion: 0.09
Nodes (7): TestClassificationResult, TestPaperlessDocument, TestReviewDecision, TestSuggestionRowEffective, Tests for Pydantic model validation., Paperless API returns many more fields — they should be ignored., Tests for effective_* fallback properties on SuggestionRow.

### Community 58 - "Community 58"
Cohesion: 0.12
Nodes (3): TestMaybeCorrectOcr, TestBatchCorrectDocuments, Tests for OCR correction: heuristic, mode dispatch, vision, fallback, and cache.

### Community 65 - "Community 65"
Cohesion: 0.13
Nodes (8): TestTextLooksBroken, Texts under 50 chars should never trigger correction., High ? ratio indicates unrecognized glyphs., Many single-char words indicate broken tokenization., High ratio of unusual characters., Normal single-char words (articles, abbreviations) shouldn't trigger., Just under 2% threshold should pass., Just over 2% threshold should trigger.

### Community 241 - "Community 241"
Cohesion: 0.50
Nodes (1): TestOcrResponseParsing

### Community 166 - "Community 166"
Cohesion: 0.33
Nodes (1): TestEffectiveOcrMode

### Community 167 - "Community 167"
Cohesion: 0.33
Nodes (1): TestSplitTextByPages

### Community 347 - "Community 347"
Cohesion: 1.00
Nodes (1): TestOcrCache

### Community 428 - "Community 428"
Cohesion: 1.00
Nodes (1): Text mode should call chat_json with model=ollama.ocr_model.

### Community 429 - "Community 429"
Cohesion: 1.00
Nodes (1): Text mode should skip correction when text looks fine.

### Community 430 - "Community 430"
Cohesion: 1.00
Nodes (1): Forced OCR should call the text model even when text looks clean.

### Community 431 - "Community 431"
Cohesion: 1.00
Nodes (1): vision_light without paperless client should fall back to text mode.

### Community 432 - "Community 432"
Cohesion: 1.00
Nodes (1): vision_full should run even when text looks fine (no heuristic gate).

### Community 433 - "Community 433"
Cohesion: 1.00
Nodes (1): Text mode should pass ollama_ocr_num_ctx to chat_json.

### Community 434 - "Community 434"
Cohesion: 1.00
Nodes (1): vision_full should pass ollama_ocr_num_ctx to chat_vision_json.

### Community 435 - "Community 435"
Cohesion: 1.00
Nodes (1): batch_correct_documents should fetch documents from Paperless API,         from

### Community 436 - "Community 436"
Cohesion: 1.00
Nodes (1): Documents already in PostgreSQL should be skipped (force=False).

### Community 437 - "Community 437"
Cohesion: 1.00
Nodes (1): With force=True, even cached documents should be processed.

### Community 438 - "Community 438"
Cohesion: 1.00
Nodes (1): With force=True, batch OCR refresh should call the model for clean text.

### Community 439 - "Community 439"
Cohesion: 1.00
Nodes (1): When OCR mode is off, should return 0 without calling Paperless.

### Community 59 - "Community 59"
Cohesion: 0.12
Nodes (9): test_is_retryable_covers_additional_transport_errors(), test_unload_model_sleeps_for_swap_delay(), test_unload_model_skips_sleep_when_zero(), test_unload_model_no_sleep_without_swap(), Tests for OllamaClient.embed() retry/truncation and chat_json() parsing., Retryability includes Pool/Write timeouts and protocol/read-write errors., unload_model(swap=True) waits for the configured swap delay., unload_model(swap=True) does not sleep when swap delay is 0. (+1 more)

### Community 49 - "Community 49"
Cohesion: 0.11
Nodes (18): _make_response(), test_embed_succeeds_without_retry(), test_embed_retries_on_transient_500_then_succeeds(), test_embed_retries_exhausted_raises(), test_embed_no_retry_on_4xx(), test_embed_retries_on_429(), test_embed_retries_on_connect_error(), test_embed_context_length_error_truncates_and_retries() (+10 more)

### Community 348 - "Community 348"
Cohesion: 1.00
Nodes (2): test_embed_context_length_progressive_truncation(), Multiple context-length errors cause progressive truncation.

### Community 349 - "Community 349"
Cohesion: 1.00
Nodes (2): test_embed_retry_disabled_when_zero(), With retries=0, errors raise immediately with provider body.

### Community 48 - "Community 48"
Cohesion: 0.11
Nodes (18): _make_chat_response(), test_chat_json_handles_bare_json(), test_chat_json_strips_markdown_fences(), test_chat_json_strips_bare_fences(), test_chat_json_strips_yaml_fence(), test_chat_json_raises_on_invalid_content(), test_chat_json_retries_once_on_invalid_json_then_succeeds(), test_chat_json_invalid_json_retry_enforces_strict_payload() (+10 more)

### Community 350 - "Community 350"
Cohesion: 1.00
Nodes (2): test_chat_json_retries_on_read_timeout(), ReadTimeout should be retried once for chat JSON requests.

### Community 351 - "Community 351"
Cohesion: 1.00
Nodes (2): test_chat_json_passes_num_ctx(), The payload sent to Ollama includes num_ctx in options.

### Community 352 - "Community 352"
Cohesion: 1.00
Nodes (2): test_chat_json_passes_custom_num_ctx(), Explicit num_ctx override takes precedence over settings default.

### Community 353 - "Community 353"
Cohesion: 1.00
Nodes (2): test_chat_json_retries_on_transient_500(), chat_json retries once on transient 500 and then succeeds.

### Community 354 - "Community 354"
Cohesion: 1.00
Nodes (2): test_chat_vision_json_passes_default_num_ctx(), Vision chat uses settings.ollama_num_ctx when no override is given.

### Community 34 - "Community 34"
Cohesion: 0.11
Nodes (7): _make_minimal_pdf(), TestContentTypeDetection, TestRenderPdfPages, TestPageCount, TestUnsupportedType, Tests for PDF/image rendering to base64., Create a minimal valid PDF with the given number of pages using PyMuPDF.

### Community 78 - "Community 78"
Cohesion: 0.21
Nodes (5): FakeConnection, FakeBegin, FakeEngine, test_publish_pipeline_event_logs_string_levels_without_structlog_type_error(), test_success_pipeline_event_level_is_mirrored_as_info_log()

### Community 50 - "Community 50"
Cohesion: 0.16
Nodes (9): FakeCursor, FakeConnection, test_python_child_owns_dedicated_session_for_complete_lease(), test_readiness_is_revalidated_on_lease_owning_session(), test_parent_protocol_does_not_share_or_transfer_a_lease_to_child(), test_acquisition_failure_remains_primary_when_close_also_fails(), test_callback_failure_remains_primary_when_unlock_fails(), test_close_failure_is_propagated_after_successful_unlock() (+1 more)

### Community 46 - "Community 46"
Cohesion: 0.15
Nodes (7): FakeResult, FakeConnection, FakeEngine, test_start_pipeline_item_creates_running_item(), test_finish_pipeline_item_updates_status(), test_progress_from_pipeline_items_derives_counts(), test_start_or_resume_pipeline_item_uses_stable_item_key()

### Community 39 - "Community 39"
Cohesion: 0.13
Nodes (8): FakeResult, FakeConnection, FakeEngine, test_cancel_check_treats_already_cancelled_run_as_terminal(), test_mark_pipeline_run_cancelled_finalizes_cancel_request(), test_mark_pipeline_run_retrying_schedules_backoff(), test_mark_pipeline_run_status_updates_operator_state(), test_mark_pipeline_run_pending_clears_blocked_state()

### Community 91 - "Community 91"
Cohesion: 0.21
Nodes (4): _Connection, _Begin, _Engine, test_poll_candidate_protocol_is_deterministic_across_discovery_replay()

### Community 66 - "Community 66"
Cohesion: 0.18
Nodes (13): _module_source_paths(), _module_identity(), _static_imports(), _walk_import_graph(), test_actor_runner_import_graph_cannot_reach_legacy_sqlite(), test_import_graph_rejects_indirect_relative_app_db_through_namespace_storage(), test_import_graph_rejects_constant_relative_dynamic_import(), test_import_graph_executes_parent_initializers_for_direct_nested_import() (+5 more)

### Community 101 - "Community 101"
Cohesion: 0.24
Nodes (4): FakeConnection, FakeEngine, test_update_pipeline_run_progress_persists_snapshot(), test_update_actor_execution_progress_persists_snapshot()

### Community 196 - "Community 196"
Cohesion: 0.70
Nodes (4): _capture_progress(), test_commit_review_suggestion_actor_commits_and_marks_status(), test_commit_review_suggestion_actor_schedules_retry_for_transient_failure(), test_commit_review_suggestion_actor_skips_missing_record()

### Community 51 - "Community 51"
Cohesion: 0.14
Nodes (4): FakeResult, FakeConnection, FakeEngine, test_list_review_suggestions_ready_to_commit()

### Community 18 - "Community 18"
Cohesion: 0.09
Nodes (9): FakeResult, FakeConnection, FakeEngine, test_classified_document_ids_returns_durable_review_markers(), test_store_review_suggestion_inserts_pending_laravel_review(), test_store_review_suggestion_does_not_overwrite_reviewed_conflict(), SequenceConnection, SequenceEngine (+1 more)

### Community 114 - "Community 114"
Cohesion: 0.33
Nodes (5): _capture_progress(), test_webhook_actor_refuses_retired_python_pipeline_start_path(), test_webhook_actor_refreshes_embedding_for_updated_events(), test_webhook_actor_marks_invalid_persisted_action_failed_permanent(), test_webhook_actor_schedules_transient_failure_for_laravel_recovery()

## Knowledge Gaps
- **313 isolated node(s):** `Absurd queue backend for the event-driven pipeline.`, `Return a queue name with the configured Archibot prefix.`, `Callable wrapper exposing an optional ``send`` attribute.`, `Adapter for registering callable tasks with Absurd.`, `Compatibility wrapper for actor call sites.` (+308 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Community 245`** (1 nodes): `Import-graph guard fixture package.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 303`** (1 nodes): `Queue-backed actor package for the event-driven Archibot pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 199`** (2 nodes): `run_async()`, `_commit_review_suggestion_impl()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 356`** (1 nodes): `Harden a chat payload for JSON-recovery retries.          Used after malformed J`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 357`** (1 nodes): `Check if a 500 response is caused by input exceeding the context length.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 358`** (1 nodes): `Exponential backoff with jitter for retry attempt ``attempt``.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 359`** (1 nodes): `Parse JSON content, handling occasional markdown fence wrappers.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 361`** (1 nodes): `Ignore every legacy confidence threshold under ADR-0018.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 362`** (1 nodes): `Treat empty env values for typed settings as unset.          Docker Compose/.env`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 363`** (1 nodes): `Named AI provider profiles, always including the default profile.          `ai_p`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 364`** (1 nodes): `Expected embedding vector dimension.          `ollama_embed_dim=0` enables auto`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 307`** (1 nodes): `Event helpers for the event-driven Archibot pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 308`** (1 nodes): `Canonical event names for the event-driven pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 309`** (1 nodes): `Shared job helpers for Absurd actors.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 246`** (1 nodes): `Idempotency helper for persisted webhook deliveries.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 204`** (1 nodes): `Lock key helpers for event-driven pipeline coordination.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 67`** (1 nodes): `Compatibility facade for Python recovery transitions.  Productive redispatch thr`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 366`** (1 nodes): `Accept common loose tag outputs from LLMs.          Normal form is a list of obj`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 247`** (1 nodes): `ArchibotReset`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 248`** (1 nodes): `CommitReviewSuggestion`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 138`** (1 nodes): `DispatchMaintenanceCommand`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 249`** (1 nodes): `PruneAuditLogs`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 250`** (1 nodes): `RecoverPipelineActors`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 251`** (1 nodes): `ResetSetup`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 252`** (1 nodes): `SchedulePollReconciliation`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 170`** (1 nodes): `AuditLogController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 104`** (1 nodes): `MaintenanceController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 52`** (1 nodes): `SettingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 313`** (1 nodes): `Controller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 139`** (1 nodes): `DashboardController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 175`** (1 nodes): `EmbeddingIndexController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 214`** (1 nodes): `EmbeddingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 83`** (1 nodes): `EntityApprovalController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 176`** (1 nodes): `ErrorsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 140`** (1 nodes): `HealthCheckController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 141`** (1 nodes): `InboxController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 215`** (1 nodes): `MaintenanceCommandController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 84`** (1 nodes): `OcrReviewController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 216`** (1 nodes): `OperationsLogController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 61`** (1 nodes): `PaperlessEventWebhookController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 85`** (1 nodes): `PipelineRunController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 26`** (1 nodes): `ReviewSuggestionController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 177`** (1 nodes): `SetupController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 106`** (1 nodes): `StatsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 96`** (1 nodes): `WebhookDeliveryController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 223`** (1 nodes): `EnsureSetupIsComplete`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 260`** (1 nodes): `EnsureUserIsAdmin`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 261`** (1 nodes): `HandleAppearance`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 224`** (1 nodes): `HandleInertiaRequests`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 119`** (1 nodes): `ValidatePaperlessWebhookRequest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 222`** (1 nodes): `ApplyEntityApprovalCommand`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 68`** (1 nodes): `RunPythonActorJob`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 155`** (1 nodes): `ActorExecution`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 225`** (1 nodes): `AppSetting`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 226`** (1 nodes): `AuditLog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 227`** (1 nodes): `ChatMessage`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 156`** (1 nodes): `ChatSession`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 228`** (1 nodes): `Command`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 262`** (1 nodes): `DocumentEmbedding`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 263`** (1 nodes): `EmbeddingIndexState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 157`** (1 nodes): `EntityApproval`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 317`** (1 nodes): `LlmCall`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 182`** (1 nodes): `OcrReview`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 183`** (1 nodes): `PipelineEvent`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 264`** (1 nodes): `PipelineItem`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 143`** (1 nodes): `PipelineRun`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 184`** (1 nodes): `PollCandidate`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 99`** (1 nodes): `ReviewSuggestion`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 158`** (1 nodes): `SetupState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 185`** (1 nodes): `User`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 229`** (1 nodes): `WebhookDelivery`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 159`** (1 nodes): `AppServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 120`** (1 nodes): `FortifyServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 243`** (1 nodes): `ActorInvocationClaim`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 197`** (1 nodes): `ActorInvocationClaimer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 115`** (1 nodes): `PythonActorOutcome`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 70`** (1 nodes): `PythonActorRunner`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 186`** (1 nodes): `ArchibotResetService`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 54`** (1 nodes): `EntityApprovalDecisionService`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 221`** (1 nodes): `ResponseSizeGuard`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 187`** (1 nodes): `OllamaClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 160`** (1 nodes): `CanonicalPaperlessOrigin`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 15`** (1 nodes): `PaperlessClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 144`** (1 nodes): `PaperlessDocumentPermissions`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 318`** (1 nodes): `PaperlessUnavailableException`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 188`** (1 nodes): `PaperlessUser`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 86`** (1 nodes): `DocumentPipelineStarter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 62`** (1 nodes): `MaintenanceCommandDispatcher`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 189`** (1 nodes): `PipelineContentStateNormalizer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 230`** (1 nodes): `PipelineLifecycleRecorder`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 4`** (1 nodes): `PipelineRecoveryDispatcher`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 121`** (1 nodes): `PipelineStartGate`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 265`** (1 nodes): `PipelineStartResult`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 73`** (1 nodes): `PollCandidateConsumer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 266`** (1 nodes): `PollCandidateLease`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 231`** (1 nodes): `LegacySettingsImporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 122`** (1 nodes): `PythonRuntimeConfigExporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 190`** (1 nodes): `SettingsCatalog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 267`** (1 nodes): `CompleteSetup`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 109`** (1 nodes): `PaperlessWebhookNormalizer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 103`** (1 nodes): `ActiveOperationsSnapshot`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 191`** (1 nodes): `BuildInfo`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 19`** (1 nodes): `DiagnosticPresenter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 232`** (1 nodes): `EmbeddingIndexSnapshot`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 145`** (1 nodes): `OperatorPrincipal`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 255`** (1 nodes): `EntityApprovalFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 256`** (1 nodes): `ReviewSuggestionFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 178`** (1 nodes): `UserFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 294`** (1 nodes): `DatabaseSeeder`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 295`** (2 nodes): `controlStatements`, `paddingAroundControl`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 253`** (2 nodes): `DialogContext`, `DIALOG_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 254`** (2 nodes): `DropdownMenuContext`, `DROPDOWN_MENU_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 180`** (2 nodes): `INPUT_OTP_CONTEXT`, `InputOTPContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 296`** (2 nodes): `SheetContext`, `SHEET_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 297`** (1 nodes): `InitialsApi`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 237`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 192`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 339`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 304`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 305`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 201`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 310`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 314`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 315`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 316`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 329`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 330`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 331`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 332`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 333`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 334`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 335`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 336`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 337`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 338`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 257`** (1 nodes): `ActiveOperationsSnapshotTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 40`** (1 nodes): `AdminSettingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 200`** (1 nodes): `AuditLogsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 244`** (1 nodes): `AuditPruneCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 133`** (1 nodes): `MaintenanceCliUiEquivalenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 71`** (1 nodes): `MaintenanceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 95`** (1 nodes): `AuthenticationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 173`** (1 nodes): `LocalAccountManagementDisabledTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 153`** (1 nodes): `ChatTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 258`** (1 nodes): `DashboardTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 102`** (1 nodes): `DiagnosticAuthorizationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 118`** (1 nodes): `EmbeddingIndexControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 28`** (1 nodes): `EntityApprovalTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 259`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 217`** (1 nodes): `HealthCheckTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 154`** (1 nodes): `InboxTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 107`** (1 nodes): `MaintenanceCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 27`** (1 nodes): `OcrReviewTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 69`** (1 nodes): `DocumentPipelineStartServiceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 7`** (1 nodes): `PipelineRecoveryDispatcherTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 76`** (1 nodes): `PollCandidateConsumerTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 127`** (1 nodes): `PostgresActorFencingUpgradeTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 147`** (1 nodes): `PostgresPipelineFenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 128`** (1 nodes): `PostgresWebhookPollConcurrencyTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 129`** (1 nodes): `PythonActorSubprocessMatrixTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 36`** (1 nodes): `RunPythonActorJobTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 97`** (1 nodes): `PipelineRunControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 218`** (1 nodes): `PipelineRunVisibilityTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 111`** (1 nodes): `EmbeddingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 219`** (1 nodes): `QueueConfigurationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 193`** (1 nodes): `ReviewCliEquivalenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 16`** (1 nodes): `ReviewSuggestionTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 108`** (1 nodes): `ScheduledPollReconciliationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 298`** (1 nodes): `LegacySettingsImportTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 13`** (1 nodes): `FirstRunSetupTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 179`** (1 nodes): `StatsAndErrorsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 98`** (1 nodes): `WebhookDeliveryControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 11`** (1 nodes): `PaperlessEventWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 238`** (1 nodes): `PaperlessWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 220`** (1 nodes): `SQLiteActorExecutionSql`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 44`** (1 nodes): `PostgresCliUiTerminalEquivalenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 181`** (1 nodes): `PostgresEntityDecisionConcurrencyTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 161`** (1 nodes): `TestCase`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 88`** (1 nodes): `DiagnosticPresenterTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 299`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 194`** (1 nodes): `PaperlessWebhookNormalizerTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 130`** (1 nodes): `PythonActorOutcomeTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 341`** (1 nodes): `Nested legacy storage fixture module.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 300`** (1 nodes): `Optional live smoke test for the PostgreSQL-backed Absurd queue.  Run with a mig`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 240`** (1 nodes): `Tests for the neutral AI-provider seam.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 427`** (1 nodes): `Ollama failures must not raise — caller keeps the initial result.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 112`** (1 nodes): `The operator CLI must not double as a productive Python actor contract.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 150`** (1 nodes): `Tests for pgvector-backed context builder compatibility facade.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 241`** (1 nodes): `TestOcrResponseParsing`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 166`** (1 nodes): `TestEffectiveOcrMode`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 167`** (1 nodes): `TestSplitTextByPages`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 347`** (1 nodes): `TestOcrCache`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 428`** (1 nodes): `Text mode should call chat_json with model=ollama.ocr_model.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 429`** (1 nodes): `Text mode should skip correction when text looks fine.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 430`** (1 nodes): `Forced OCR should call the text model even when text looks clean.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 431`** (1 nodes): `vision_light without paperless client should fall back to text mode.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 432`** (1 nodes): `vision_full should run even when text looks fine (no heuristic gate).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 433`** (1 nodes): `Text mode should pass ollama_ocr_num_ctx to chat_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 434`** (1 nodes): `vision_full should pass ollama_ocr_num_ctx to chat_vision_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 435`** (1 nodes): `batch_correct_documents should fetch documents from Paperless API,         from`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 436`** (1 nodes): `Documents already in PostgreSQL should be skipped (force=False).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 437`** (1 nodes): `With force=True, even cached documents should be processed.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 438`** (1 nodes): `With force=True, batch OCR refresh should call the model for clean text.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 439`** (1 nodes): `When OCR mode is off, should return 0 without calling Paperless.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 348`** (2 nodes): `test_embed_context_length_progressive_truncation()`, `Multiple context-length errors cause progressive truncation.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 349`** (2 nodes): `test_embed_retry_disabled_when_zero()`, `With retries=0, errors raise immediately with provider body.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 350`** (2 nodes): `test_chat_json_retries_on_read_timeout()`, `ReadTimeout should be retried once for chat JSON requests.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 351`** (2 nodes): `test_chat_json_passes_num_ctx()`, `The payload sent to Ollama includes num_ctx in options.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 352`** (2 nodes): `test_chat_json_passes_custom_num_ctx()`, `Explicit num_ctx override takes precedence over settings default.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 353`** (2 nodes): `test_chat_json_retries_on_transient_500()`, `chat_json retries once on transient 500 and then succeeds.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 354`** (2 nodes): `test_chat_vision_json_passes_default_num_ctx()`, `Vision chat uses settings.ollama_num_ctx when no override is given.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `PaperlessClient` connect `Community 1` to `Community 20`, `Community 0`, `Community 6`, `Community 72`, `Community 60`, `Community 3`?**
  _High betweenness centrality (0.009) - this node is a cross-community bridge._
- **Why does `PaperlessDocument` connect `Community 0` to `Community 31`, `Community 20`, `Community 1`, `Community 10`, `Community 60`, `Community 3`, `Community 137`?**
  _High betweenness centrality (0.006) - this node is a cross-community bridge._
- **Why does `ExecutionLifecycle` connect `Community 1` to `Community 14`, `Community 20`, `Community 0`, `Community 41`?**
  _High betweenness centrality (0.006) - this node is a cross-community bridge._
- **Are the 95 inferred relationships involving `PaperlessDocument` (e.g. with `EntityCatalog` and `DocumentClassificationOutcome`) actually correct?**
  _`PaperlessDocument` has 95 INFERRED edges - model-reasoned connections that need verification._
- **Are the 58 inferred relationships involving `PaperlessClient` (e.g. with `EntityCatalog` and `DocumentClassificationOutcome`) actually correct?**
  _`PaperlessClient` has 58 INFERRED edges - model-reasoned connections that need verification._
- **Are the 50 inferred relationships involving `AiProviderGateway` (e.g. with `EntityCatalog` and `DocumentClassificationOutcome`) actually correct?**
  _`AiProviderGateway` has 50 INFERRED edges - model-reasoned connections that need verification._
- **Are the 42 inferred relationships involving `PaperlessEntity` (e.g. with `EntityCatalog` and `DocumentClassificationOutcome`) actually correct?**
  _`PaperlessEntity` has 42 INFERRED edges - model-reasoned connections that need verification._