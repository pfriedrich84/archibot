# Graph Report - .  (2026-07-18)

## Corpus Check
- Large corpus: 600 files · ~231,031 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder, or use --no-semantic to run AST-only.

## Summary
- 3389 nodes · 7676 edges · 232 communities detected
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 504 edges (avg confidence: 0.5)
- Token cost: 0 input · 0 output
- Edge kinds: ON_BRANCH: 1600 · MODIFIES: 1344 · method: 1213 · calls: 1147 · contains: 1056 · uses: 504 · rationale_for: 355 · PARENT_OF: 261 · imports_from: 162 · inherits: 23 · imports: 9 · re_exports: 2


## Input Scope
- Requested: all
- Resolved: all (source: cli)
- Included files: 600 · Candidates: recursive
- Excluded: 0 untracked · 0 ignored · 9 sensitive · 0 missing committed

## Graph Freshness
- Built from Git commit: `18fa97d`
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
- `Factory for the configured AI-provider adapter.` --uses--> `AiProviderClient`  [INFERRED]
  app/ai_provider/factory.py → app/ai_provider/client.py
- `Create the configured AI-provider adapter.      Existing OLLAMA_* and OpenAI-com` --uses--> `AiProviderClient`  [INFERRED]
  app/ai_provider/factory.py → app/ai_provider/client.py
- `Neutral AI-provider seam for ArchiBot runtime code.` --uses--> `AiProviderClient`  [INFERRED]
  app/ai_provider/__init__.py → app/ai_provider/client.py
- `Start without a product-state backend or privileged global Paperless client.` --uses--> `Deps`  [INFERRED]
  app/mcp_server.py → app/mcp_tools/_deps.py
- `Backward-compatible Ollama-named import for the neutral AI-provider client.  Arc` --uses--> `AiProviderClient`  [INFERRED]
  app/clients/ollama.py → app/ai_provider/client.py

## Communities

### Community 3 - "Community 3"
Cohesion: 0.03
Nodes (23): Import-graph guard fixture package., _text(), rejected_entity_names(), PostgreSQL repository for durable entity approval/blacklist state., Return rejected names from Laravel's shared entity approval table., register(), Correspondent proposal operations remain retired pending an authorized PostgreSQ, Intentionally register nothing; see the MCP disposition matrix. (+15 more)

### Community 28 - "Community 28"
Cohesion: 0.17
Nodes (25): ActorRunnerError, RuntimeError, _build_initial_embedding_index_impl(), _reconcile_inbox_documents_impl(), _reindex_ocr_documents_impl(), _handle_document_pipeline_impl(), _commit_review_suggestion_impl(), _handle_paperless_webhook_impl() (+17 more)

### Community 54 - "Community 54"
Cohesion: 0.33
Nodes (16): Fixed Python actor command runner for Laravel queued actor jobs.  Laravel databa, Raised when a fixed actor command cannot be executed safely., Return the optional positive integer limit from a durable command payload., Return a boolean payload flag from durable command payload values., Run an embedding build while the Python child owns the exclusive lease., Run polling reconciliation from the durable command payload., Run reindex while the Python child owns the exclusive mutation lease., Run OCR reindex from the durable command payload. (+8 more)

### Community 44 - "Community 44"
Cohesion: 0.13
Nodes (10): Plain Python actor implementations invoked by Laravel database queue jobs., _coerce_limit(), _build_pgvector_embeddings(), _build_initial_embedding_index_impl(), _fetch_inbox_documents(), _modified_value(), _reconcile_inbox_documents_impl(), Shared durable job-state helpers for fixed Python actors. (+2 more)

### Community 21 - "Community 21"
Cohesion: 0.11
Nodes (22): run_async(), _fetch_paperless_document(), _load_entity_catalog(), _classify_document(), _update_item_derived_progress(), start_pipeline_item(), _phase_item(), _record_completed_phase_item() (+14 more)

### Community 7 - "Community 7"
Cohesion: 0.09
Nodes (43): EntityCatalog, DocumentClassificationOutcome, DocumentPipelineCancelled, Exception, Document processing actors for the event-driven pipeline., Start/resume a document actor phase item by stable key.      Kept as a module-le, Raised internally when cancellation is observed between phases., Handle one document pipeline run through durable event-driven steps.      The pr (+35 more)

### Community 17 - "Community 17"
Cohesion: 0.12
Nodes (29): Embedding actors and initial embedding-index build actors., Build the initial PostgreSQL/pgvector document embedding index., _reindex_ocr_documents_impl(), Maintenance actors for polling reconciliation, recovery and reindex., Poll Paperless inbox as reconciliation and use the shared pipeline start., Run OCR reindex through the durable maintenance actor path., Review and commit actors for accepted suggestions., InvalidWebhookAction (+21 more)

### Community 5 - "Community 5"
Cohesion: 0.09
Nodes (15): run_async(), _commit_review_suggestion_impl(), Canonical event names for the event-driven pipeline., worker_id(), Runtime context helpers for actors., Return host, PID and Linux process-start identity for actor liveness checks., RecoverPipelineActors, SchedulePollReconciliation (+7 more)

### Community 140 - "Community 140"
Cohesion: 0.67
Nodes (5): EmbeddingRefreshResult, _refresh_document_embedding_async(), refresh_document_embedding(), _delete_document_embedding(), _handle_paperless_webhook_impl()

### Community 9 - "Community 9"
Cohesion: 0.08
Nodes (22): Neutral AI-provider seam for ArchiBot runtime code., create_ai_provider(), Factory for the configured AI-provider adapter., Create the configured AI-provider adapter.      Existing OLLAMA_* and OpenAI-com, Backward-compatible Ollama-named import for the neutral AI-provider client.  Arc, Permission-scoped MCP server with no local product-state backend., McpIdentity, Verified MCP caller identity returned by Laravel. (+14 more)

### Community 12 - "Community 12"
Cohesion: 0.11
Nodes (22): _strip_markdown_fences(), _exc_to_str(), AiProviderClient, _parse_chat_json_content(), _make_strict_json_retry_payload(), _make_strict_openai_json_retry_payload(), _is_context_length_error(), _http_error_detail() (+14 more)

### Community 253 - "Community 253"
Cohesion: 1.00
Nodes (1): Harden a chat payload for JSON-recovery retries.          Used after malformed J

### Community 254 - "Community 254"
Cohesion: 1.00
Nodes (1): Check if a 500 response is caused by input exceeding the context length.

### Community 255 - "Community 255"
Cohesion: 1.00
Nodes (1): Exponential backoff with jitter for retry attempt ``attempt``.

### Community 256 - "Community 256"
Cohesion: 1.00
Nodes (1): Parse JSON content, handling occasional markdown fence wrappers.

### Community 89 - "Community 89"
Cohesion: 0.36
Nodes (10): _configure_logging(), _laravel_artisan_path(), _run_artisan(), cmd_laravel_maintenance(), cmd_reset(), cmd_commit_review(), _reject_unknown_args(), _positive_int() (+2 more)

### Community 10 - "Community 10"
Cohesion: 0.07
Nodes (23): PaperlessClient, _new_entity_payload(), Paperless-NGX REST API client., Return all documents tagged with the inbox tag, paginated., Apply metadata changes to a document., Download the original document file (PDF/image).          Returns ``(file_bytes,, Download Paperless' browser-friendly preview rendition.          Paperless expos, Full-text search with optional filters. (+15 more)

### Community 4 - "Community 4"
Cohesion: 0.06
Nodes (30): require_postgresql_database_url(), _apply_config_env_overrides(), assert_product_database_config(), _FieldMeta, dict, Application configuration via pydantic-settings (.env-driven)., Return a PostgreSQL URL or fail closed before any product DB is opened., Apply config.env overrides with highest priority.      Docker-compose injects .e (+22 more)

### Community 30 - "Community 30"
Cohesion: 0.09
Nodes (17): Settings, BaseSettings, All runtime settings. Everything is driven from environment variables., Ignore every legacy confidence threshold under ADR-0018., Treat empty env values for typed settings as unset.          Docker Compose/.env, Named AI provider profiles, always including the default profile.          `ai_p, Expected embedding vector dimension.          `ollama_embed_dim=0` enables auto, config_env_path() (+9 more)

### Community 241 - "Community 241"
Cohesion: 1.00
Nodes (1): Event helpers for the event-driven Archibot pipeline.

### Community 35 - "Community 35"
Cohesion: 0.12
Nodes (13): protocol_failure(), sanitize_error(), start(), start_actor_execution(), schedule_actor_execution_retry(), update_actor_execution_progress(), update_item_derived_progress(), recover_stale_executions() (+5 more)

### Community 58 - "Community 58"
Cohesion: 0.20
Nodes (16): _PostgresqlActorExecutionSql, StaleActorExecutionRecord, sql_text(), start_actor_execution(), _transition_source_for_execution(), _release_source_fence(), finish_actor_execution(), schedule_actor_execution_retry() (+8 more)

### Community 1 - "Community 1"
Cohesion: 0.04
Nodes (66): sql_text(), load_command(), _list_pending_commands(), list_pending_embedding_build_commands(), list_pending_poll_reconciliation_commands(), list_pending_reindex_commands(), list_pending_ocr_reindex_commands(), list_pending_review_commit_commands() (+58 more)

### Community 51 - "Community 51"
Cohesion: 0.22
Nodes (17): DocumentEmbeddingRow, sql_text(), document_embedding_text(), content_hash_for_text(), pgvector_literal(), _metadata_value(), _modified_value(), _tags_value() (+9 more)

### Community 6 - "Community 6"
Cohesion: 0.08
Nodes (45): DocumentEmbeddingInput, PostgreSQL/pgvector document embedding persistence and trusted context search., Return bounded text used for pgvector embeddings., Return a pgvector-compatible vector literal without logging values., Persist a document embedding in PostgreSQL/pgvector.      Returns the content ha, Delete all stored embeddings for one Paperless document., Delete old embeddings for one document after a newer content hash is stored., Return trusted pgvector nearest-neighbour document ids and distances. (+37 more)

### Community 13 - "Community 13"
Cohesion: 0.06
Nodes (13): Idempotency helper for persisted webhook deliveries., PollCandidateResult, persist_poll_candidate(), Durable discovery handoff from Python polling to Laravel Pipeline Start., Persist one idempotent protocol-v1 candidate; never create a Pipeline Run., PollCandidate, PipelineContentStateNormalizer, PipelineLifecycleRecorder (+5 more)

### Community 179 - "Community 179"
Cohesion: 0.50
Nodes (1): Lock key helpers for event-driven pipeline coordination.

### Community 115 - "Community 115"
Cohesion: 0.36
Nodes (7): OcrCorrection, _text(), store_ocr_correction(), cached_ocr_correction(), cached_ocr_document_ids(), PostgreSQL repository for local-only OCR corrections., Idempotently persist corrected OCR text in the shared schema.

### Community 79 - "Community 79"
Cohesion: 0.21
Nodes (12): _psycopg_database_url(), _add_cleanup_note(), _lease(), document_actor_lease(), embedding_mutation_lease(), embedding_index_ready(), PostgreSQL advisory leases shared by productive Python pipeline actors.  The lea, Attach a secondary cleanup failure without replacing the primary error. (+4 more)

### Community 31 - "Community 31"
Cohesion: 0.12
Nodes (23): DocumentPipelineRunRecord, sql_text(), load_document_pipeline_run(), list_embedding_blocked_pipeline_run_ids(), list_cancel_requested_pipeline_run_ids(), is_pipeline_run_cancel_requested(), mark_pipeline_run_cancelled(), list_pending_document_pipeline_run_ids() (+15 more)

### Community 116 - "Community 116"
Cohesion: 0.25
Nodes (7): retry_backoff_seconds(), should_retry(), classify_exception(), Retry classification contracts for event-driven actors., Return the bounded default backoff for a 1-based retry attempt., Return whether an actor should schedule another durable attempt., Classify common actor exceptions without logging sensitive payloads.      The cl

### Community 95 - "Community 95"
Cohesion: 0.36
Nodes (9): sql_text(), _json(), _safe_context_documents(), _entity_id(), _proposed_tags(), _raw_payload(), _upsert_entity_approval(), classified_document_ids() (+1 more)

### Community 41 - "Community 41"
Cohesion: 0.13
Nodes (18): from_laravel_payload(), RateLimiter, _extract_token(), _find_token(), _verify_laravel_mcp_token(), _set_mcp_identity(), get_mcp_identity(), check_api_key() (+10 more)

### Community 181 - "Community 181"
Cohesion: 0.50
Nodes (3): register(), Identity-less FastMCP resources retired because they cannot enforce per-user per, Intentionally register nothing; see the MCP disposition matrix.

### Community 182 - "Community 182"
Cohesion: 0.50
Nodes (3): register(), Suggestion reads and decisions retired until the Laravel Review seam is exposed, Intentionally register nothing; see the MCP disposition matrix.

### Community 24 - "Community 24"
Cohesion: 0.09
Nodes (10): register(), System status retired because diagnostics require an admin-only PostgreSQL redac, Intentionally register nothing; see the MCP disposition matrix., document_summary(), Shared context DTOs and text helpers for classification context., Short, embedding-friendly text representation of a document., Tests for pgvector-backed context builder compatibility facade., 532d2fd feat: move context search to pgvector (+2 more)

### Community 26 - "Community 26"
Cohesion: 0.10
Nodes (16): _coerce_optional_entity_id(), _coerce_entity_id_list(), BaseModel, _coerce_entity_id(), _coerce_tags(), ProposedTag, TagWhitelistEntry, TagBlacklistEntry (+8 more)

### Community 78 - "Community 78"
Cohesion: 0.28
Nodes (12): SuggestionRow, EmbeddingResult, ClassificationDraft, JudgedDraft, StoredSuggestionResult, BatchProcessResult, Small data models for Dokument-Verarbeitung.  These result/intermediate types ar, Intermediate result from the embedding phase for a single Dokument. (+4 more)

### Community 259 - "Community 259"
Cohesion: 1.00
Nodes (1): Accept common loose tag outputs from LLMs.          Normal form is a list of obj

### Community 37 - "Community 37"
Cohesion: 0.20
Nodes (21): _load_system_prompt(), _load_judge_system_prompt(), _truncate(), _estimate_tokens(), _tokens_to_chars(), _format_document_block(), _resolve_entity_name(), _format_context_block() (+13 more)

### Community 45 - "Community 45"
Cohesion: 0.22
Nodes (19): _sanitize_ocr_text(), _parse_ocr_response(), ocr_requested_tag_id(), configured_ocr_tag_exists(), should_run_ocr_for_document(), effective_ocr_mode(), maybe_correct_ocr(), cache_ocr_correction() (+11 more)

### Community 83 - "Community 83"
Cohesion: 0.26
Nodes (11): render_document_pages(), _is_pdf(), _is_image(), _render_pdf_pages(), _render_image(), page_count(), Render document files (PDF/image) to base64-encoded images for vision models., Convert a document file to a list of base64-encoded JPEG images.      For PDFs, (+3 more)

### Community 135 - "Community 135"
Cohesion: 0.33
Nodes (6): trusted_context_scope(), _tag_id(), is_trusted_document(), Trusted Document rules for classification context., Return the durable scope name for Trusted Document context., Return whether a Paperless Document is trusted classification context.      Doma

### Community 82 - "Community 82"
Cohesion: 0.32
Nodes (11): PromptSpec, get_prompt_spec(), default_prompt_path(), override_prompt_path(), load_default_prompt(), load_prompt(), validate_prompt(), save_prompt() (+3 more)

### Community 141 - "Community 141"
Cohesion: 0.33
Nodes (5): escape_html(), encode_path_segment(), Helpers for safely rendering small HTML fragments and user-visible errors., Escape a value for safe insertion into inline HTML fragments., Encode a value for use in URLs / DOM ids derived from path segments.

### Community 8 - "Community 8"
Cohesion: 0.04
Nodes (10): ArchibotReset, PruneAuditLogs, OperationsLogController, lang, lang, lang, AuditPruneCommandTest, HealthCheckTest (+2 more)

### Community 142 - "Community 142"
Cohesion: 0.60
Nodes (1): DispatchMaintenanceCommand

### Community 198 - "Community 198"
Cohesion: 0.67
Nodes (1): ResetSetup

### Community 155 - "Community 155"
Cohesion: 0.50
Nodes (1): AuditLogController

### Community 108 - "Community 108"
Cohesion: 0.36
Nodes (1): MaintenanceController

### Community 63 - "Community 63"
Cohesion: 0.29
Nodes (1): SettingsController

### Community 240 - "Community 240"
Cohesion: 1.00
Nodes (1): Controller

### Community 0 - "Community 0"
Cohesion: 0.06
Nodes (212): PaperlessUnavailableException, lang, lang, lang, lang, lang, lang, lang (+204 more)

### Community 143 - "Community 143"
Cohesion: 0.60
Nodes (1): DashboardController

### Community 176 - "Community 176"
Cohesion: 0.67
Nodes (1): EmbeddingIndexController

### Community 199 - "Community 199"
Cohesion: 0.67
Nodes (1): EmbeddingsController

### Community 91 - "Community 91"
Cohesion: 0.45
Nodes (1): EntityApprovalController

### Community 158 - "Community 158"
Cohesion: 0.50
Nodes (1): ErrorsController

### Community 128 - "Community 128"
Cohesion: 0.48
Nodes (1): HealthCheckController

### Community 129 - "Community 129"
Cohesion: 0.48
Nodes (1): InboxController

### Community 200 - "Community 200"
Cohesion: 0.67
Nodes (1): MaintenanceCommandController

### Community 69 - "Community 69"
Cohesion: 0.26
Nodes (5): lang, lang, 26b58f2 fix(security): keep OCR corrections local, 4103488 fix(setup): pin and secure bootstrap origin, d4e0d66 Merge pull request #227 from pfriedrich84/hardening/step-03-ocr-local-permissions

### Community 92 - "Community 92"
Cohesion: 0.35
Nodes (1): OcrReviewController

### Community 70 - "Community 70"
Cohesion: 0.26
Nodes (1): PaperlessEventWebhookController

### Community 93 - "Community 93"
Cohesion: 0.33
Nodes (1): PipelineRunController

### Community 32 - "Community 32"
Cohesion: 0.18
Nodes (1): ReviewSuggestionController

### Community 68 - "Community 68"
Cohesion: 0.20
Nodes (5): SetupController, CompleteSetup, 2a904da Merge pull request #228 from pfriedrich84/hardening/step-04-webhook-fail-closed, bd7cbef fix(security): fail closed on webhook ingress, eef5276 test(security): fix webhook hardening assertions

### Community 110 - "Community 110"
Cohesion: 0.43
Nodes (1): StatsController

### Community 100 - "Community 100"
Cohesion: 0.40
Nodes (1): WebhookDeliveryController

### Community 183 - "Community 183"
Cohesion: 0.67
Nodes (1): EnsureSetupIsComplete

### Community 209 - "Community 209"
Cohesion: 0.67
Nodes (1): EnsureUserIsAdmin

### Community 210 - "Community 210"
Cohesion: 0.67
Nodes (1): HandleAppearance

### Community 211 - "Community 211"
Cohesion: 0.67
Nodes (1): HandleInertiaRequests

### Community 131 - "Community 131"
Cohesion: 0.43
Nodes (1): ValidatePaperlessWebhookRequest

### Community 72 - "Community 72"
Cohesion: 0.20
Nodes (1): RunPythonActorJob

### Community 162 - "Community 162"
Cohesion: 0.40
Nodes (1): ActorExecution

### Community 184 - "Community 184"
Cohesion: 0.50
Nodes (1): AppSetting

### Community 185 - "Community 185"
Cohesion: 0.50
Nodes (1): AuditLog

### Community 186 - "Community 186"
Cohesion: 0.50
Nodes (1): ChatMessage

### Community 145 - "Community 145"
Cohesion: 0.33
Nodes (1): ChatSession

### Community 187 - "Community 187"
Cohesion: 0.50
Nodes (1): Command

### Community 225 - "Community 225"
Cohesion: 0.67
Nodes (1): DocumentEmbedding

### Community 226 - "Community 226"
Cohesion: 0.67
Nodes (1): EmbeddingIndexState

### Community 146 - "Community 146"
Cohesion: 0.33
Nodes (1): EntityApproval

### Community 244 - "Community 244"
Cohesion: 1.00
Nodes (1): LlmCall

### Community 188 - "Community 188"
Cohesion: 0.50
Nodes (1): OcrReview

### Community 163 - "Community 163"
Cohesion: 0.40
Nodes (1): PipelineEvent

### Community 227 - "Community 227"
Cohesion: 0.67
Nodes (1): PipelineItem

### Community 132 - "Community 132"
Cohesion: 0.29
Nodes (1): PipelineRun

### Community 103 - "Community 103"
Cohesion: 0.20
Nodes (1): ReviewSuggestion

### Community 147 - "Community 147"
Cohesion: 0.33
Nodes (1): SetupState

### Community 164 - "Community 164"
Cohesion: 0.40
Nodes (1): User

### Community 228 - "Community 228"
Cohesion: 0.67
Nodes (1): WebhookDelivery

### Community 167 - "Community 167"
Cohesion: 0.60
Nodes (1): AppServiceProvider

### Community 122 - "Community 122"
Cohesion: 0.39
Nodes (1): FortifyServiceProvider

### Community 196 - "Community 196"
Cohesion: 0.67
Nodes (1): ActorInvocationClaim

### Community 172 - "Community 172"
Cohesion: 0.50
Nodes (1): ActorInvocationClaimer

### Community 107 - "Community 107"
Cohesion: 0.29
Nodes (1): PythonActorOutcome

### Community 76 - "Community 76"
Cohesion: 0.35
Nodes (1): PythonActorRunner

### Community 169 - "Community 169"
Cohesion: 0.60
Nodes (1): ArchibotResetService

### Community 60 - "Community 60"
Cohesion: 0.24
Nodes (1): EntityApprovalDecisionService

### Community 178 - "Community 178"
Cohesion: 0.50
Nodes (1): ResponseSizeGuard

### Community 165 - "Community 165"
Cohesion: 0.50
Nodes (1): OllamaClient

### Community 148 - "Community 148"
Cohesion: 0.67
Nodes (1): CanonicalPaperlessOrigin

### Community 22 - "Community 22"
Cohesion: 0.13
Nodes (1): PaperlessClient

### Community 149 - "Community 149"
Cohesion: 0.60
Nodes (1): PaperlessDocumentPermissions

### Community 166 - "Community 166"
Cohesion: 0.40
Nodes (1): PaperlessUser

### Community 97 - "Community 97"
Cohesion: 0.33
Nodes (1): DocumentPipelineStarter

### Community 71 - "Community 71"
Cohesion: 0.36
Nodes (1): MaintenanceCommandDispatcher

### Community 11 - "Community 11"
Cohesion: 0.11
Nodes (1): PipelineRecoveryDispatcher

### Community 118 - "Community 118"
Cohesion: 0.39
Nodes (1): PipelineStartGate

### Community 74 - "Community 74"
Cohesion: 0.29
Nodes (1): PollCandidateConsumer

### Community 190 - "Community 190"
Cohesion: 0.67
Nodes (1): LegacySettingsImporter

### Community 137 - "Community 137"
Cohesion: 0.52
Nodes (1): PythonRuntimeConfigExporter

### Community 191 - "Community 191"
Cohesion: 0.67
Nodes (1): SettingsCatalog

### Community 126 - "Community 126"
Cohesion: 0.46
Nodes (1): PaperlessWebhookNormalizer

### Community 105 - "Community 105"
Cohesion: 0.39
Nodes (1): ActiveOperationsSnapshot

### Community 170 - "Community 170"
Cohesion: 0.40
Nodes (1): BuildInfo

### Community 27 - "Community 27"
Cohesion: 0.15
Nodes (1): DiagnosticPresenter

### Community 192 - "Community 192"
Cohesion: 0.67
Nodes (1): EmbeddingIndexSnapshot

### Community 138 - "Community 138"
Cohesion: 0.29
Nodes (1): OperatorPrincipal

### Community 203 - "Community 203"
Cohesion: 0.67
Nodes (1): EntityApprovalFactory

### Community 204 - "Community 204"
Cohesion: 0.67
Nodes (1): ReviewSuggestionFactory

### Community 159 - "Community 159"
Cohesion: 0.40
Nodes (1): UserFactory

### Community 117 - "Community 117"
Cohesion: 0.50
Nodes (7): up(), down(), decodeJson(), eventType(), webhookAction(), containsAny(), updateNormalizedPayload()

### Community 230 - "Community 230"
Cohesion: 0.67
Nodes (1): DatabaseSeeder

### Community 207 - "Community 207"
Cohesion: 0.67
Nodes (2): controlStatements, paddingAroundControl

### Community 15 - "Community 15"
Cohesion: 0.08
Nodes (25): initializeFlashToast(), ThemeState, appearance, prefersDark(), isDarkMode(), getResolvedAppearance(), setCookie(), applyTheme() (+17 more)

### Community 201 - "Community 201"
Cohesion: 0.67
Nodes (2): DialogContext, DIALOG_CONTEXT

### Community 202 - "Community 202"
Cohesion: 0.67
Nodes (2): DropdownMenuContext, DROPDOWN_MENU_CONTEXT

### Community 160 - "Community 160"
Cohesion: 0.40
Nodes (2): INPUT_OTP_CONTEXT, InputOTPContext

### Community 96 - "Community 96"
Cohesion: 0.22
Nodes (3): CurrentUrlState, cn(), toUrl()

### Community 232 - "Community 232"
Cohesion: 0.67
Nodes (2): SheetContext, SHEET_CONTEXT

### Community 52 - "Community 52"
Cohesion: 0.13
Nodes (3): SidebarContext, SIDEBAR_CONTEXT, useSidebar()

### Community 59 - "Community 59"
Cohesion: 0.21
Nodes (14): DisplayPreferences, preferences(), userTimezone(), userFormat(), partsFor(), isIsoDateTime(), formatDate(), formatDateTime() (+6 more)

### Community 208 - "Community 208"
Cohesion: 0.67
Nodes (1): InitialsApi

### Community 180 - "Community 180"
Cohesion: 0.67
Nodes (3): PaperlessEntityOption, numericId(), paperlessLabel()

### Community 245 - "Community 245"
Cohesion: 1.00
Nodes (1): lang

### Community 235 - "Community 235"
Cohesion: 1.00
Nodes (1): lang

### Community 247 - "Community 247"
Cohesion: 1.00
Nodes (1): lang

### Community 49 - "Community 49"
Cohesion: 0.11
Nodes (1): AdminSettingsTest

### Community 197 - "Community 197"
Cohesion: 0.67
Nodes (1): AuditLogsTest

### Community 77 - "Community 77"
Cohesion: 0.15
Nodes (1): MaintenanceTest

### Community 90 - "Community 90"
Cohesion: 0.27
Nodes (1): AuthenticationTest

### Community 156 - "Community 156"
Cohesion: 0.40
Nodes (1): LocalAccountManagementDisabledTest

### Community 157 - "Community 157"
Cohesion: 0.60
Nodes (1): ChatTest

### Community 101 - "Community 101"
Cohesion: 0.20
Nodes (1): DiagnosticAuthorizationTest

### Community 36 - "Community 36"
Cohesion: 0.15
Nodes (1): EntityApprovalTest

### Community 205 - "Community 205"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 144 - "Community 144"
Cohesion: 0.33
Nodes (1): InboxTest

### Community 113 - "Community 113"
Cohesion: 0.25
Nodes (1): MaintenanceCommandTest

### Community 33 - "Community 33"
Cohesion: 0.19
Nodes (1): OcrReviewTest

### Community 73 - "Community 73"
Cohesion: 0.16
Nodes (1): DocumentPipelineStartServiceTest

### Community 16 - "Community 16"
Cohesion: 0.12
Nodes (1): PipelineRecoveryDispatcherTest

### Community 80 - "Community 80"
Cohesion: 0.38
Nodes (1): PollCandidateConsumerTest

### Community 119 - "Community 119"
Cohesion: 0.32
Nodes (1): PostgresActorFencingUpgradeTest

### Community 133 - "Community 133"
Cohesion: 0.57
Nodes (1): PostgresPipelineFenceTest

### Community 120 - "Community 120"
Cohesion: 0.46
Nodes (1): PostgresWebhookPollConcurrencyTest

### Community 134 - "Community 134"
Cohesion: 0.33
Nodes (1): PythonActorSubprocessMatrixTest

### Community 46 - "Community 46"
Cohesion: 0.18
Nodes (1): RunPythonActorJobTest

### Community 94 - "Community 94"
Cohesion: 0.18
Nodes (1): PipelineRunControlTest

### Community 121 - "Community 121"
Cohesion: 0.25
Nodes (1): EmbeddingsTest

### Community 206 - "Community 206"
Cohesion: 0.67
Nodes (1): QueueConfigurationTest

### Community 168 - "Community 168"
Cohesion: 0.40
Nodes (1): ReviewCliEquivalenceTest

### Community 23 - "Community 23"
Cohesion: 0.06
Nodes (1): ReviewSuggestionTest

### Community 114 - "Community 114"
Cohesion: 0.25
Nodes (1): ScheduledPollReconciliationTest

### Community 231 - "Community 231"
Cohesion: 0.67
Nodes (1): LegacySettingsImportTest

### Community 20 - "Community 20"
Cohesion: 0.09
Nodes (1): FirstRunSetupTest

### Community 177 - "Community 177"
Cohesion: 0.50
Nodes (1): StatsAndErrorsTest

### Community 102 - "Community 102"
Cohesion: 0.36
Nodes (1): WebhookDeliveryControlTest

### Community 19 - "Community 19"
Cohesion: 0.09
Nodes (1): PaperlessEventWebhookTest

### Community 234 - "Community 234"
Cohesion: 0.67
Nodes (1): PaperlessWebhookTest

### Community 50 - "Community 50"
Cohesion: 0.27
Nodes (1): PostgresCliUiTerminalEquivalenceTest

### Community 161 - "Community 161"
Cohesion: 0.50
Nodes (1): PostgresEntityDecisionConcurrencyTest

### Community 154 - "Community 154"
Cohesion: 0.47
Nodes (1): TestCase

### Community 88 - "Community 88"
Cohesion: 0.17
Nodes (1): DiagnosticPresenterTest

### Community 233 - "Community 233"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 195 - "Community 195"
Cohesion: 0.50
Nodes (1): PaperlessWebhookNormalizerTest

### Community 125 - "Community 125"
Cohesion: 0.25
Nodes (1): PythonActorOutcomeTest

### Community 84 - "Community 84"
Cohesion: 0.24
Nodes (11): load_allowlist(), get_installed_packages(), get_release_date(), _parse_version(), check_cve_fix(), main(), Load package==version pairs that are exempted from the age check., Return [(name, version), ...] from pip freeze. (+3 more)

### Community 136 - "Community 136"
Cohesion: 0.52
Nodes (6): _read_text(), check_expected_files(), check_content_patterns(), _portable_check_command(), check_graphify_portability(), main()

### Community 189 - "Community 189"
Cohesion: 0.83
Nodes (3): iter_markdown_files(), is_external(), main()

### Community 38 - "Community 38"
Cohesion: 0.18
Nodes (21): Violation, productive_files(), _line(), legacy_reference_fingerprints(), load_legacy_fingerprint_baseline(), _string_fragment(), _python_name(), _dotted_name() (+13 more)

### Community 229 - "Community 229"
Cohesion: 0.67
Nodes (3): scan_sqlite_product_state(), Deny retired SQLite/runtime state across every productive repository file., Deny retired SQLite/runtime state across every productive repository file.

### Community 64 - "Community 64"
Cohesion: 0.13
Nodes (11): sample_entities(), sample_context_doc(), sample_doc(), mock_paperless(), mock_ollama(), Shared fixtures for the test suite., A small set of entities for resolution tests., A classified document suitable as context (not in inbox). (+3 more)

### Community 39 - "Community 39"
Cohesion: 0.13
Nodes (8): FakeResult, FakeConnection, FakeRows, FakeEngine, test_start_actor_execution_inserts_running_row(), test_finish_actor_execution_updates_status(), test_schedule_actor_execution_retry_updates_retry_metadata(), test_list_stale_running_actor_executions_returns_records()

### Community 34 - "Community 34"
Cohesion: 0.12
Nodes (9): fenced(), test_every_actor_family_has_fixed_protocol_identity(), test_every_actor_family_real_subprocess_emits_protocol_failure_on_bootstrap_failure(), test_main_build_embedding_index_invokes_command(), test_main_process_document_invokes_pipeline_run(), test_main_handle_webhook_invokes_delivery(), test_main_commit_review_invokes_command(), test_main_reconcile_poll_invokes_command() (+1 more)

### Community 14 - "Community 14"
Cohesion: 0.06
Nodes (15): _postgres_blacklist_repository(), TestResolveEntityName, TestFormatContextBlock, TestFormatDocumentBlock, TestBuildUserPrompt, TestNormalizationHelpers, TestPromptBudget, Tests for the classifier prompt builder and entity resolution. (+7 more)

### Community 55 - "Community 55"
Cohesion: 0.14
Nodes (11): _postgres_blacklist_repository(), _initial_result(), TestParseJudgeVerdict, TestBuildJudgeUserPrompt, TestVerify, test_verify_agree_roundtrip(), test_verify_corrected_returns_new_result(), test_verify_transport_error_becomes_error_verdict() (+3 more)

### Community 297 - "Community 297"
Cohesion: 1.00
Nodes (1): Ollama failures must not raise — caller keeps the initial result.

### Community 42 - "Community 42"
Cohesion: 0.14
Nodes (9): FakeRows, FakeConnection, FakeEngine, test_load_command_returns_command_record(), test_load_command_returns_none_when_missing(), test_list_pending_embedding_build_commands_returns_payload(), test_list_pending_poll_reconciliation_commands_returns_payload(), test_list_pending_reindex_commands_returns_payload() (+1 more)

### Community 123 - "Community 123"
Cohesion: 0.25
Nodes (1): TestEnvFileIO

### Community 98 - "Community 98"
Cohesion: 0.18
Nodes (1): TestSaveConfig

### Community 139 - "Community 139"
Cohesion: 0.33
Nodes (3): _run_module_with_database_url(), test_product_entry_points_fail_closed_for_sqlite_database_url(), PostgreSQL-only product startup and engine regression tests.

### Community 47 - "Community 47"
Cohesion: 0.12
Nodes (5): FakeResult, FakeConnection, FakeEngine, test_store_document_embedding_persists_pgvector_metadata(), test_find_similar_document_ids_uses_pgvector_trusted_filters()

### Community 65 - "Community 65"
Cohesion: 0.17
Nodes (6): FakeResult, FakeConnection, FakeEngine, test_embedding_gate_allows_only_complete_status(), test_embedding_gate_fails_closed_without_state(), test_embedding_gate_fails_closed_for_incomplete_status()

### Community 61 - "Community 61"
Cohesion: 0.16
Nodes (7): FakeResult, FakeConnection, FakeEngine, test_start_embedding_index_build_creates_building_state(), test_start_embedding_index_build_returns_existing_build(), test_update_embedding_index_progress_persists_counts(), test_finish_embedding_index_build_updates_status()

### Community 48 - "Community 48"
Cohesion: 0.12
Nodes (4): Result, Connection, Engine, test_restart_reconstructs_versioned_outcome()

### Community 150 - "Community 150"
Cohesion: 0.60
Nodes (5): _actor(), test_poll_reconciliation_persists_marked_and_unmarked_candidates(), test_forced_poll_bypasses_marker_and_persists_force_metadata(), test_poll_reconciliation_schedules_retry_for_transient_fetch_failure(), test_poll_reconciliation_skips_without_inbox_tag()

### Community 75 - "Community 75"
Cohesion: 0.21
Nodes (9): CapturingMcp, make_ctx(), test_verified_identity_requires_laravel_and_complete_paperless_context(), test_verified_identity_rejects_revoked_token(), test_verified_identity_rejects_disabled_laravel_auth(), test_verified_identity_rejects_incomplete_paperless_context(), test_read_only_identity_cannot_use_write_guard(), test_every_baseline_module_is_retired_until_laravel_postgres_seams_exist() (+1 more)

### Community 29 - "Community 29"
Cohesion: 0.09
Nodes (7): TestClassificationResult, TestPaperlessDocument, TestReviewDecision, TestSuggestionRowEffective, Tests for Pydantic model validation., Paperless API returns many more fields — they should be ignored., Tests for effective_* fallback properties on SuggestionRow.

### Community 62 - "Community 62"
Cohesion: 0.12
Nodes (3): TestMaybeCorrectOcr, TestBatchCorrectDocuments, Tests for OCR correction: heuristic, mode dispatch, vision, fallback, and cache.

### Community 66 - "Community 66"
Cohesion: 0.13
Nodes (8): TestTextLooksBroken, Texts under 50 chars should never trigger correction., High ? ratio indicates unrecognized glyphs., Many single-char words indicate broken tokenization., High ratio of unusual characters., Normal single-char words (articles, abbreviations) shouldn't trigger., Just under 2% threshold should pass., Just over 2% threshold should trigger.

### Community 193 - "Community 193"
Cohesion: 0.50
Nodes (1): TestOcrResponseParsing

### Community 151 - "Community 151"
Cohesion: 0.33
Nodes (1): TestEffectiveOcrMode

### Community 152 - "Community 152"
Cohesion: 0.33
Nodes (1): TestSplitTextByPages

### Community 251 - "Community 251"
Cohesion: 1.00
Nodes (1): TestOcrCache

### Community 298 - "Community 298"
Cohesion: 1.00
Nodes (1): Text mode should call chat_json with model=ollama.ocr_model.

### Community 299 - "Community 299"
Cohesion: 1.00
Nodes (1): Text mode should skip correction when text looks fine.

### Community 300 - "Community 300"
Cohesion: 1.00
Nodes (1): Forced OCR should call the text model even when text looks clean.

### Community 301 - "Community 301"
Cohesion: 1.00
Nodes (1): vision_light without paperless client should fall back to text mode.

### Community 302 - "Community 302"
Cohesion: 1.00
Nodes (1): vision_full should run even when text looks fine (no heuristic gate).

### Community 303 - "Community 303"
Cohesion: 1.00
Nodes (1): Text mode should pass ollama_ocr_num_ctx to chat_json.

### Community 304 - "Community 304"
Cohesion: 1.00
Nodes (1): vision_full should pass ollama_ocr_num_ctx to chat_vision_json.

### Community 305 - "Community 305"
Cohesion: 1.00
Nodes (1): batch_correct_documents should fetch documents from Paperless API,         from

### Community 306 - "Community 306"
Cohesion: 1.00
Nodes (1): Documents already in PostgreSQL should be skipped (force=False).

### Community 307 - "Community 307"
Cohesion: 1.00
Nodes (1): With force=True, even cached documents should be processed.

### Community 308 - "Community 308"
Cohesion: 1.00
Nodes (1): With force=True, batch OCR refresh should call the model for clean text.

### Community 309 - "Community 309"
Cohesion: 1.00
Nodes (1): When OCR mode is off, should return 0 without calling Paperless.

### Community 2 - "Community 2"
Cohesion: 0.04
Nodes (59): _make_response(), test_embed_succeeds_without_retry(), test_embed_retries_on_transient_500_then_succeeds(), test_embed_retries_exhausted_raises(), test_embed_no_retry_on_4xx(), test_embed_retries_on_429(), test_embed_retries_on_connect_error(), test_embed_context_length_error_truncates_and_retries() (+51 more)

### Community 40 - "Community 40"
Cohesion: 0.11
Nodes (7): _make_minimal_pdf(), TestContentTypeDetection, TestRenderPdfPages, TestPageCount, TestUnsupportedType, Tests for PDF/image rendering to base64., Create a minimal valid PDF with the given number of pages using PyMuPDF.

### Community 81 - "Community 81"
Cohesion: 0.21
Nodes (5): FakeConnection, FakeBegin, FakeEngine, test_publish_pipeline_event_logs_string_levels_without_structlog_type_error(), test_success_pipeline_event_level_is_mirrored_as_info_log()

### Community 56 - "Community 56"
Cohesion: 0.16
Nodes (9): FakeCursor, FakeConnection, test_python_child_owns_dedicated_session_for_complete_lease(), test_readiness_is_revalidated_on_lease_owning_session(), test_parent_protocol_does_not_share_or_transfer_a_lease_to_child(), test_acquisition_failure_remains_primary_when_close_also_fails(), test_callback_failure_remains_primary_when_unlock_fails(), test_close_failure_is_propagated_after_successful_unlock() (+1 more)

### Community 53 - "Community 53"
Cohesion: 0.15
Nodes (7): FakeResult, FakeConnection, FakeEngine, test_start_pipeline_item_creates_running_item(), test_finish_pipeline_item_updates_status(), test_progress_from_pipeline_items_derives_counts(), test_start_or_resume_pipeline_item_uses_stable_item_key()

### Community 43 - "Community 43"
Cohesion: 0.13
Nodes (8): FakeResult, FakeConnection, FakeEngine, test_cancel_check_treats_already_cancelled_run_as_terminal(), test_mark_pipeline_run_cancelled_finalizes_cancel_request(), test_mark_pipeline_run_retrying_schedules_backoff(), test_mark_pipeline_run_status_updates_operator_state(), test_mark_pipeline_run_pending_clears_blocked_state()

### Community 87 - "Community 87"
Cohesion: 0.21
Nodes (4): _Connection, _Begin, _Engine, test_poll_candidate_protocol_is_deterministic_across_discovery_replay()

### Community 67 - "Community 67"
Cohesion: 0.18
Nodes (13): _module_source_paths(), _module_identity(), _static_imports(), _walk_import_graph(), test_actor_runner_import_graph_cannot_reach_legacy_sqlite(), test_import_graph_rejects_indirect_relative_app_db_through_namespace_storage(), test_import_graph_rejects_constant_relative_dynamic_import(), test_import_graph_executes_parent_initializers_for_direct_nested_import() (+5 more)

### Community 99 - "Community 99"
Cohesion: 0.24
Nodes (4): FakeConnection, FakeEngine, test_update_pipeline_run_progress_persists_snapshot(), test_update_actor_execution_progress_persists_snapshot()

### Community 171 - "Community 171"
Cohesion: 0.70
Nodes (4): _capture_progress(), test_commit_review_suggestion_actor_commits_and_marks_status(), test_commit_review_suggestion_actor_schedules_retry_for_transient_failure(), test_commit_review_suggestion_actor_skips_missing_record()

### Community 57 - "Community 57"
Cohesion: 0.14
Nodes (4): FakeResult, FakeConnection, FakeEngine, test_list_review_suggestions_ready_to_commit()

### Community 25 - "Community 25"
Cohesion: 0.09
Nodes (9): FakeResult, FakeConnection, FakeEngine, test_classified_document_ids_returns_durable_review_markers(), test_store_review_suggestion_inserts_pending_laravel_review(), test_store_review_suggestion_does_not_overwrite_reviewed_conflict(), SequenceConnection, SequenceEngine (+1 more)

### Community 106 - "Community 106"
Cohesion: 0.33
Nodes (5): _capture_progress(), test_webhook_actor_refuses_retired_python_pipeline_start_path(), test_webhook_actor_refreshes_embedding_for_updated_events(), test_webhook_actor_marks_invalid_persisted_action_failed_permanent(), test_webhook_actor_schedules_transient_failure_for_laravel_recovery()

## Knowledge Gaps
- **296 isolated node(s):** `Import-graph guard fixture package.`, `Plain Python actor implementations invoked by Laravel database queue jobs.`, `Neutral AI-provider client for chat, JSON responses, vision, and embeddings.`, `Extract raw JSON from Ollama responses.      Handles three cases:     1. JSON wr`, `Return a useful error string even when ``str(exc)`` is empty.` (+291 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Community 253`** (1 nodes): `Harden a chat payload for JSON-recovery retries.          Used after malformed J`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 254`** (1 nodes): `Check if a 500 response is caused by input exceeding the context length.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 255`** (1 nodes): `Exponential backoff with jitter for retry attempt ``attempt``.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 256`** (1 nodes): `Parse JSON content, handling occasional markdown fence wrappers.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 241`** (1 nodes): `Event helpers for the event-driven Archibot pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 179`** (1 nodes): `Lock key helpers for event-driven pipeline coordination.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 259`** (1 nodes): `Accept common loose tag outputs from LLMs.          Normal form is a list of obj`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 142`** (1 nodes): `DispatchMaintenanceCommand`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 198`** (1 nodes): `ResetSetup`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 155`** (1 nodes): `AuditLogController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 108`** (1 nodes): `MaintenanceController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 63`** (1 nodes): `SettingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 240`** (1 nodes): `Controller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 143`** (1 nodes): `DashboardController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 176`** (1 nodes): `EmbeddingIndexController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 199`** (1 nodes): `EmbeddingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 91`** (1 nodes): `EntityApprovalController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 158`** (1 nodes): `ErrorsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 128`** (1 nodes): `HealthCheckController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 129`** (1 nodes): `InboxController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 200`** (1 nodes): `MaintenanceCommandController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 92`** (1 nodes): `OcrReviewController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 70`** (1 nodes): `PaperlessEventWebhookController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 93`** (1 nodes): `PipelineRunController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 32`** (1 nodes): `ReviewSuggestionController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 110`** (1 nodes): `StatsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 100`** (1 nodes): `WebhookDeliveryController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 183`** (1 nodes): `EnsureSetupIsComplete`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 209`** (1 nodes): `EnsureUserIsAdmin`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 210`** (1 nodes): `HandleAppearance`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 211`** (1 nodes): `HandleInertiaRequests`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 131`** (1 nodes): `ValidatePaperlessWebhookRequest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 72`** (1 nodes): `RunPythonActorJob`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 162`** (1 nodes): `ActorExecution`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 184`** (1 nodes): `AppSetting`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 185`** (1 nodes): `AuditLog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 186`** (1 nodes): `ChatMessage`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 145`** (1 nodes): `ChatSession`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 187`** (1 nodes): `Command`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 225`** (1 nodes): `DocumentEmbedding`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 226`** (1 nodes): `EmbeddingIndexState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 146`** (1 nodes): `EntityApproval`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 244`** (1 nodes): `LlmCall`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 188`** (1 nodes): `OcrReview`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 163`** (1 nodes): `PipelineEvent`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 227`** (1 nodes): `PipelineItem`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 132`** (1 nodes): `PipelineRun`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 103`** (1 nodes): `ReviewSuggestion`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 147`** (1 nodes): `SetupState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 164`** (1 nodes): `User`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 228`** (1 nodes): `WebhookDelivery`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 167`** (1 nodes): `AppServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 122`** (1 nodes): `FortifyServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 196`** (1 nodes): `ActorInvocationClaim`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 172`** (1 nodes): `ActorInvocationClaimer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 107`** (1 nodes): `PythonActorOutcome`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 76`** (1 nodes): `PythonActorRunner`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 169`** (1 nodes): `ArchibotResetService`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 60`** (1 nodes): `EntityApprovalDecisionService`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 178`** (1 nodes): `ResponseSizeGuard`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 165`** (1 nodes): `OllamaClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 148`** (1 nodes): `CanonicalPaperlessOrigin`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 22`** (1 nodes): `PaperlessClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 149`** (1 nodes): `PaperlessDocumentPermissions`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 166`** (1 nodes): `PaperlessUser`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 97`** (1 nodes): `DocumentPipelineStarter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 71`** (1 nodes): `MaintenanceCommandDispatcher`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 11`** (1 nodes): `PipelineRecoveryDispatcher`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 118`** (1 nodes): `PipelineStartGate`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 74`** (1 nodes): `PollCandidateConsumer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 190`** (1 nodes): `LegacySettingsImporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 137`** (1 nodes): `PythonRuntimeConfigExporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 191`** (1 nodes): `SettingsCatalog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 126`** (1 nodes): `PaperlessWebhookNormalizer`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 105`** (1 nodes): `ActiveOperationsSnapshot`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 170`** (1 nodes): `BuildInfo`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 27`** (1 nodes): `DiagnosticPresenter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 192`** (1 nodes): `EmbeddingIndexSnapshot`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 138`** (1 nodes): `OperatorPrincipal`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 203`** (1 nodes): `EntityApprovalFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 204`** (1 nodes): `ReviewSuggestionFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 159`** (1 nodes): `UserFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 230`** (1 nodes): `DatabaseSeeder`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 207`** (2 nodes): `controlStatements`, `paddingAroundControl`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 201`** (2 nodes): `DialogContext`, `DIALOG_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 202`** (2 nodes): `DropdownMenuContext`, `DROPDOWN_MENU_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 160`** (2 nodes): `INPUT_OTP_CONTEXT`, `InputOTPContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 232`** (2 nodes): `SheetContext`, `SHEET_CONTEXT`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 208`** (1 nodes): `InitialsApi`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 245`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 235`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 247`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 49`** (1 nodes): `AdminSettingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 197`** (1 nodes): `AuditLogsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 77`** (1 nodes): `MaintenanceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 90`** (1 nodes): `AuthenticationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 156`** (1 nodes): `LocalAccountManagementDisabledTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 157`** (1 nodes): `ChatTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 101`** (1 nodes): `DiagnosticAuthorizationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 36`** (1 nodes): `EntityApprovalTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 205`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 144`** (1 nodes): `InboxTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 113`** (1 nodes): `MaintenanceCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 33`** (1 nodes): `OcrReviewTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 73`** (1 nodes): `DocumentPipelineStartServiceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 16`** (1 nodes): `PipelineRecoveryDispatcherTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 80`** (1 nodes): `PollCandidateConsumerTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 119`** (1 nodes): `PostgresActorFencingUpgradeTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 133`** (1 nodes): `PostgresPipelineFenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 120`** (1 nodes): `PostgresWebhookPollConcurrencyTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 134`** (1 nodes): `PythonActorSubprocessMatrixTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 46`** (1 nodes): `RunPythonActorJobTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 94`** (1 nodes): `PipelineRunControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 121`** (1 nodes): `EmbeddingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 206`** (1 nodes): `QueueConfigurationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 168`** (1 nodes): `ReviewCliEquivalenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 23`** (1 nodes): `ReviewSuggestionTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 114`** (1 nodes): `ScheduledPollReconciliationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 231`** (1 nodes): `LegacySettingsImportTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 20`** (1 nodes): `FirstRunSetupTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 177`** (1 nodes): `StatsAndErrorsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 102`** (1 nodes): `WebhookDeliveryControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 19`** (1 nodes): `PaperlessEventWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 234`** (1 nodes): `PaperlessWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 50`** (1 nodes): `PostgresCliUiTerminalEquivalenceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 161`** (1 nodes): `PostgresEntityDecisionConcurrencyTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 154`** (1 nodes): `TestCase`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 88`** (1 nodes): `DiagnosticPresenterTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 233`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 195`** (1 nodes): `PaperlessWebhookNormalizerTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 125`** (1 nodes): `PythonActorOutcomeTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 297`** (1 nodes): `Ollama failures must not raise — caller keeps the initial result.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 123`** (1 nodes): `TestEnvFileIO`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 98`** (1 nodes): `TestSaveConfig`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 193`** (1 nodes): `TestOcrResponseParsing`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 151`** (1 nodes): `TestEffectiveOcrMode`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 152`** (1 nodes): `TestSplitTextByPages`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 251`** (1 nodes): `TestOcrCache`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 298`** (1 nodes): `Text mode should call chat_json with model=ollama.ocr_model.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 299`** (1 nodes): `Text mode should skip correction when text looks fine.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 300`** (1 nodes): `Forced OCR should call the text model even when text looks clean.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 301`** (1 nodes): `vision_light without paperless client should fall back to text mode.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 302`** (1 nodes): `vision_full should run even when text looks fine (no heuristic gate).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 303`** (1 nodes): `Text mode should pass ollama_ocr_num_ctx to chat_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 304`** (1 nodes): `vision_full should pass ollama_ocr_num_ctx to chat_vision_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 305`** (1 nodes): `batch_correct_documents should fetch documents from Paperless API,         from`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 306`** (1 nodes): `Documents already in PostgreSQL should be skipped (force=False).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 307`** (1 nodes): `With force=True, even cached documents should be processed.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 308`** (1 nodes): `With force=True, batch OCR refresh should call the model for clean text.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 309`** (1 nodes): `When OCR mode is off, should return 0 without calling Paperless.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `PipelineRecoveryDispatcher` connect `Community 11` to `Community 5`?**
  _High betweenness centrality (0.022) - this node is a cross-community bridge._
- **Why does `PaperlessClient` connect `Community 10` to `Community 7`, `Community 17`, `Community 140`, `Community 4`, `Community 30`, `Community 6`, `Community 45`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Why does `PaperlessDocument` connect `Community 6` to `Community 7`, `Community 26`, `Community 10`, `Community 51`, `Community 24`, `Community 45`, `Community 78`, `Community 135`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **What connects `Import-graph guard fixture package.`, `Plain Python actor implementations invoked by Laravel database queue jobs.`, `Neutral AI-provider client for chat, JSON responses, vision, and embeddings.` to the rest of the system?**
  _296 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 3` be split into smaller, more focused modules?**
  _Cohesion score 0.03125 - nodes in this community are weakly interconnected._
- **Should `Community 44` be split into smaller, more focused modules?**
  _Cohesion score 0.12631578947368421 - nodes in this community are weakly interconnected._
- **Should `Community 21` be split into smaller, more focused modules?**
  _Cohesion score 0.11088709677419355 - nodes in this community are weakly interconnected._