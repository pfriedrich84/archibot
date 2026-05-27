# Graph Report - .  (2026-05-27)

## Corpus Check
- Large corpus: 547 files · ~208,093 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder, or use --no-semantic to run AST-only.

## Summary
- 2991 nodes · 4536 edges · 311 communities detected
- Extraction: 77% EXTRACTED · 23% INFERRED · 0% AMBIGUOUS · INFERRED: 1053 edges (avg confidence: 0.5)
- Token cost: 0 input · 0 output
- Edge kinds: contains: 1085 · uses: 1053 · method: 888 · calls: 869 · rationale_for: 510 · imports_from: 102 · inherits: 18 · imports: 9 · re_exports: 2


## Input Scope
- Requested: auto
- Resolved: committed (source: cli)
- Included files: 547 · Candidates: 604
- Excluded: 3 untracked · 42728 ignored · 9 sensitive · 0 missing committed
- Recommendation: Use --scope all or graphify.yaml inputs.corpus for a knowledge-base folder.

## Graph Freshness
- Built from Git commit: `8d62cd8`
- Compare this hash to `git rev-parse HEAD` before trusting freshness-sensitive graph output.
## God Nodes (most connected - your core abstractions)
1. `PaperlessClient` - 168 edges
2. `OllamaClient` - 165 edges
3. `PaperlessDocument` - 114 edges
4. `SuggestionRow` - 88 edges
5. `PaperlessEntity` - 75 edges
6. `ReviewDecision` - 75 edges
7. `SimilarDocument` - 62 edges
8. `ClassificationResult` - 61 edges
9. `JudgeVerdict` - 45 edges
10. `DocumentRepository` - 40 edges

## Surprising Connections (you probably didn't know these)
- `Review and commit actors for accepted suggestions.` --uses--> `PaperlessClient`  [INFERRED]
  app/actors/review.py → app/clients/paperless.py
- `Event-driven review commit helpers.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Return accepted event-driven review suggestions that need commit.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Load fields needed to patch Paperless for one accepted suggestion.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py
- `Build safe Paperless PATCH fields from reviewed IDs only.` --uses--> `PaperlessClient`  [INFERRED]
  app/jobs/review_commit.py → app/clients/paperless.py

## Communities

### Community 0 - "Community 0"
Cohesion: 0.06
Nodes (126): ClassificationResult, CorrespondentBlacklistEntry, CorrespondentWhitelistEntry, DoctypeBlacklistEntry, DoctypeWhitelistEntry, JudgeVerdict, PaperlessDocument, PaperlessEntity (+118 more)

### Community 1 - "Community 1"
Cohesion: 0.08
Nodes (60): _arg_value(), _chat_contract_payload(), cmd_chat_ask(), cmd_commit_review(), cmd_jobs(), cmd_poll(), cmd_process_doc(), cmd_reindex() (+52 more)

### Community 2 - "Community 2"
Cohesion: 0.09
Nodes (38): Document processing actors for the event-driven pipeline., Handle one document pipeline run through durable event-driven steps.      This a, OllamaClient, _apply_metadata_filter(), document_summary(), find_similar_by_id(), find_similar_by_query_text(), find_similar_by_query_text_filtered() (+30 more)

### Community 3 - "Community 3"
Cohesion: 0.08
Nodes (24): _EntityCache, Cached Paperless entity lists — fetched once per session., cancel_reindex(), _emit_reindex_progress(), enable_reindex_progress_stdout(), get_reindex_progress(), initial_index(), is_reindexing() (+16 more)

### Community 4 - "Community 4"
Cohesion: 0.10
Nodes (20): _backoff_delay(), _exc_to_str(), _http_error_detail(), _is_context_length_error(), _is_retryable(), _make_strict_json_retry_payload(), _make_strict_openai_json_retry_payload(), _parse_chat_json_content() (+12 more)

### Community 5 - "Community 5"
Cohesion: 0.08
Nodes (28): _accept_via_telegram(), _build_suggestion_message(), _handle_callback(), _handle_message(), notify_job_summary(), notify_suggestion(), _poll_loop(), Telegram bot: send suggestion notifications, handle callbacks, and RAG chat. (+20 more)

### Community 6 - "Community 6"
Cohesion: 0.06
Nodes (13): Tests for the classifier prompt builder and entity resolution., Tags with IDs not in the entity list should be silently skipped., With num_ctx=4096 and a large doc, prompt stays within budget., With a very small num_ctx, context docs get dropped., Without context docs, target document gets the full document budget., A doc with no classification should only show title + content., Only populated metadata lines should appear., TestBuildUserPrompt (+5 more)

### Community 7 - "Community 7"
Cohesion: 0.09
Nodes (31): _configure_logging(), lifespan(), MCP server exposing Paperless-NGX operations and AI classification tools., Set up structlog — stderr only, so stdio transport stays clean., Initialize clients and DB, yield Deps, cleanup on shutdown., check_api_key(), _extract_token(), _find_token() (+23 more)

### Community 8 - "Community 8"
Cohesion: 0.08
Nodes (25): initializeFlashToast(), appearance, applyTheme(), detachThemeChangeListener(), getResolvedAppearance(), getStoredAppearance(), handleSystemThemeChange(), initializeTheme() (+17 more)

### Community 9 - "Community 9"
Cohesion: 0.10
Nodes (33): batch_correct_documents(), cache_ocr_correction(), configured_ocr_tag_exists(), _correct_text_only(), _correct_vision_full(), _correct_vision_light(), effective_ocr_mode(), get_cached_ocr() (+25 more)

### Community 10 - "Community 10"
Cohesion: 0.09
Nodes (26): _build_initial_embedding_index_impl(), _build_pgvector_embeddings(), Embedding actors and initial embedding-index build actors., Build the initial PostgreSQL/pgvector document embedding index., _fetch_inbox_documents(), _modified_value(), Maintenance actors for polling reconciliation, recovery and reindex., Poll Paperless inbox as reconciliation and use the shared pipeline start. (+18 more)

### Community 11 - "Community 11"
Cohesion: 0.07
Nodes (1): WorkerJob

### Community 12 - "Community 12"
Cohesion: 0.11
Nodes (28): ask(), ask_stateless(), _budget_context_blocks(), _build_chat_user_message(), ChatResult, ChatSession, delete_chat_session(), _ensure_entity_cache() (+20 more)

### Community 13 - "Community 13"
Cohesion: 0.07
Nodes (6): Tests for the RAG chat feature — OllamaClient.chat(), find_similar_by_query_text, TestAskPipeline, TestChatSystemPrompt, TestOllamaChatMethod, TestSessionManagement, TestTelegramChatHandler

### Community 14 - "Community 14"
Cohesion: 0.09
Nodes (7): Tests for Pydantic model validation., Paperless API returns many more fields — they should be ignored., Tests for effective_* fallback properties on SuggestionRow., TestClassificationResult, TestPaperlessDocument, TestReviewDecision, TestSuggestionRowEffective

### Community 15 - "Community 15"
Cohesion: 0.12
Nodes (24): DocumentPipelineRunRecord, list_cancel_requested_pipeline_run_ids(), list_due_retrying_document_pipeline_run_ids(), list_embedding_blocked_pipeline_run_ids(), list_pending_document_pipeline_run_ids(), load_document_pipeline_run(), mark_pipeline_run_cancelled(), mark_pipeline_run_pending() (+16 more)

### Community 16 - "Community 16"
Cohesion: 0.11
Nodes (24): cancel_poll(), get_poll_progress(), _has_embedding_index(), is_polling(), poll_inbox(), APScheduler-based background worker for inbox polling and classification., Launch ``poll_inbox`` as a background asyncio task.      Returns ``True`` if sta, Fetch inbox or all Paperless documents and run the classification pipeline. (+16 more)

### Community 17 - "Community 17"
Cohesion: 0.15
Nodes (18): CapturingMcp, DummyCompletedProcess, enable_laravel_mcp_auth(), make_ctx(), make_laravel_ctx(), NoGlobalPaperless, NoopRateLimiter, test_get_paperless_returns_scoped_client_from_verified_identity() (+10 more)

### Community 18 - "Community 18"
Cohesion: 0.13
Nodes (23): _enqueue_command_actor(), enqueue_document_pipeline_run(), enqueue_embedding_build_command(), enqueue_poll_reconciliation_command(), enqueue_reindex_command(), enqueue_review_commit(), enqueue_webhook_delivery(), finalize_cancel_requested_runs() (+15 more)

### Community 19 - "Community 19"
Cohesion: 0.16
Nodes (1): PaperlessClient

### Community 20 - "Community 20"
Cohesion: 0.09
Nodes (19): db_conn(), _mock_get_conn(), mock_ollama(), mock_paperless(), patch_db(), Shared fixtures for the test suite., A classified document suitable as context (not in inbox)., A minimal test document. (+11 more)

### Community 21 - "Community 21"
Cohesion: 0.12
Nodes (9): FakeConnection, FakeEngine, FakeResult, FakeRows, test_finish_actor_execution_updates_status(), test_list_stale_running_actor_executions_returns_records(), test_mark_stale_actor_execution_recovered_updates_retry_state(), test_schedule_actor_execution_retry_updates_retry_metadata() (+1 more)

### Community 22 - "Community 22"
Cohesion: 0.15
Nodes (18): _make_doc(), Tests for worker entity resolution, tag handling, and poll cycle logging., test_agree_keeps_initial_result(), test_all_embeds_before_all_classifies(), test_classify_uses_precomputed_context(), test_corrected_result_replaces_initial(), test_disabled_returns_initial_unchanged(), test_embed_called_once_per_doc() (+10 more)

### Community 23 - "Community 23"
Cohesion: 0.20
Nodes (21): build_judge_user_prompt(), build_user_prompt(), _clamp_confidence(), _classification_max_tags(), _classification_to_prompt_json(), classify(), _estimate_tokens(), _format_context_block() (+13 more)

### Community 24 - "Community 24"
Cohesion: 0.09
Nodes (1): ReviewSuggestionTest

### Community 25 - "Community 25"
Cohesion: 0.11
Nodes (7): _make_minimal_pdf(), Tests for PDF/image rendering to base64., Create a minimal valid PDF with the given number of pages using PyMuPDF., TestContentTypeDetection, TestPageCount, TestRenderPdfPages, TestUnsupportedType

### Community 26 - "Community 26"
Cohesion: 0.13
Nodes (9): FakeConnection, FakeEngine, FakeResult, test_mark_pipeline_run_cancelled_finalizes_cancel_request(), test_mark_pipeline_run_pending_clears_blocked_state(), test_mark_pipeline_run_retrying_schedules_backoff(), test_mark_pipeline_run_status_updates_operator_state(), test_upsert_document_pipeline_run_persists_blocked_run() (+1 more)

### Community 27 - "Community 27"
Cohesion: 0.18
Nodes (1): ReviewSuggestionController

### Community 28 - "Community 28"
Cohesion: 0.15
Nodes (19): _make_doc(), Tests for the context builder: inbox filtering and similarity search via sqlite-, Create a test document, optionally in the inbox., test_delegates_to_find_similar_documents(), test_empty_results(), test_excludes_self(), test_excludes_source_doc(), test_falls_back_to_vector_when_no_fts() (+11 more)

### Community 29 - "Community 29"
Cohesion: 0.10
Nodes (11): Tests for database schema and operations., Verify all expected tables are created., name is the primary key — duplicates should conflict., document_id is the primary key — duplicates should conflict., Verify we can insert a suggestion and read it back., Verify processed_documents can be written and updated., Verify tag whitelist insert and counter update., Verify error records can be inserted. (+3 more)

### Community 31 - "Community 31"
Cohesion: 0.14
Nodes (20): _insert_suggestion(), Tests for retroactive tag application on approval., If the document already has the tag, skip the PATCH., If no suggestions contain the tag, counts should be zero., Tag name matching should be case-insensitive., If the document was deleted in Paperless, skip gracefully., Retroactive tag application should create an audit log entry., Should handle multiple suggestions across committed and pending. (+12 more)

### Community 32 - "Community 32"
Cohesion: 0.19
Nodes (1): PythonWorkerCommand

### Community 33 - "Community 33"
Cohesion: 0.12
Nodes (19): _create_db_files(), data_dir(), Tests for the CLI reset command., The CLI must not silently fall back to the legacy SQLite reset., main() exits with error when --yes is missing., Set DATA_DIR to a temp directory and mock the Laravel reset runner., Create fake legacy DB + WAL/SHM files and return their paths., The Python CLI keeps the command but runs the Laravel/PostgreSQL reset. (+11 more)

### Community 34 - "Community 34"
Cohesion: 0.10
Nodes (3): Tests for config_writer — env file I/O and save_config logic., TestEnvFileIO, TestSaveConfig

### Community 35 - "Community 35"
Cohesion: 0.17
Nodes (12): _approval_snapshot(), _daily_commits(), get_dashboard_snapshot(), get_recent_errors(), get_stats_snapshot(), get_system_status(), get_tags_snapshot(), _last_poll() (+4 more)

### Community 36 - "Community 36"
Cohesion: 0.12
Nodes (11): _apply_config_env_overrides(), _db_requires_setup(), _essential_config_missing(), _FieldMeta, needs_setup(), Application configuration via pydantic-settings (.env-driven)., Apply config.env overrides with highest priority.      Docker-compose injects .e, True when the DB is missing, empty, invalid, or still uninitialized. (+3 more)

### Community 37 - "Community 37"
Cohesion: 0.13
Nodes (3): SIDEBAR_CONTEXT, SidebarContext, useSidebar()

### Community 38 - "Community 38"
Cohesion: 0.11
Nodes (1): WorkerJobTest

### Community 39 - "Community 39"
Cohesion: 0.16
Nodes (1): FirstRunSetupTest

### Community 40 - "Community 40"
Cohesion: 0.20
Nodes (1): PythonWorkerCommandTest

### Community 41 - "Community 41"
Cohesion: 0.16
Nodes (7): FakeConnection, FakeEngine, FakeRows, test_list_pending_embedding_build_commands_returns_payload(), test_list_pending_poll_reconciliation_commands_returns_payload(), test_list_pending_reindex_commands_returns_payload(), test_mark_command_status_updates_bridge_status()

### Community 42 - "Community 42"
Cohesion: 0.20
Nodes (17): _make_decision(), _make_suggestion(), Tests for the committer pipeline., A suggested storage path may be applied only to documents without one., Existing storage paths are preserved even if the decision contains another one., None values for optional fields should not be sent., On Paperless API error, exception is swallowed and error recorded., Verify the PATCH payload is assembled correctly. (+9 more)

### Community 43 - "Community 43"
Cohesion: 0.11
Nodes (18): _make_chat_response(), Build a minimal httpx.Response mimicking an Ollama /api/chat reply., Clean JSON parses without issues (regression check)., JSON wrapped in ```json ... ``` fences should be parsed successfully., JSON wrapped in ``` ... ``` (no language tag) should also parse., JSON prefixed with '---' (YAML frontmatter delimiter) should parse., Truly invalid (non-JSON, non-fenced) content raises ValueError., A transient malformed JSON response should be retried once. (+10 more)

### Community 44 - "Community 44"
Cohesion: 0.11
Nodes (18): _make_response(), Successful embed on first attempt — no retries needed., Transient 500 (not context-length) triggers backoff retry., Build a minimal httpx.Response for testing., All retries exhausted — raises provider body details., 4xx client error (not 429) raises immediately with provider body., 429 rate limit triggers retry., ConnectError triggers retry. (+10 more)

### Community 45 - "Community 45"
Cohesion: 0.15
Nodes (6): FakeConnection, FakeEngine, FakeResult, test_finish_pipeline_item_updates_status(), test_progress_from_pipeline_items_derives_counts(), test_start_pipeline_item_creates_running_item()

### Community 46 - "Community 46"
Cohesion: 0.14
Nodes (4): FakeConnection, FakeEngine, FakeResult, test_list_review_suggestions_ready_to_commit()

### Community 47 - "Community 47"
Cohesion: 0.26
Nodes (1): SettingsController

### Community 48 - "Community 48"
Cohesion: 0.18
Nodes (14): All runtime settings. Everything is driven from environment variables., Settings, apply_runtime_changes(), config_env_path(), Persistent config: read/write {DATA_DIR}/config.env and hot-reload settings., Recreate clients / reschedule jobs for changed fields.      Returns a list of hu, Return path to the persistent config.env inside the data directory., Parse a KEY=VALUE env file.  Skips comments and blank lines. (+6 more)

### Community 49 - "Community 49"
Cohesion: 0.21
Nodes (14): DisplayPreferences, formatDate(), formatDateTime(), isIsoDateTime(), partsFor(), preferences(), userFormat(), userTimezone() (+6 more)

### Community 50 - "Community 50"
Cohesion: 0.12
Nodes (1): Tests for Laravel worker JSON CLI contract.

### Community 51 - "Community 51"
Cohesion: 0.17
Nodes (15): _connect(), get_conn(), init_db(), mark_setup_complete(), mark_setup_required(), _migrate(), _migrate_embed_dim(), SQLite setup with sqlite-vec extension and schema migrations. (+7 more)

### Community 52 - "Community 52"
Cohesion: 0.18
Nodes (15): _audit_retroactive_skip(), commit_suggestion(), _document_has_inbox_tag(), Write accepted suggestions back to Paperless-NGX via PATCH., Retroactively apply a newly approved tag to affected suggestions.      Finds all, Return True while a document is still part of the Paperless inbox flow., Retroactively apply a newly approved correspondent to affected suggestions., Persist a clear audit record for skipped retroactive alignment. (+7 more)

### Community 53 - "Community 53"
Cohesion: 0.16
Nodes (9): _initial_result(), Tests for the LLM-as-judge verification pass in classifier.verify()., If the 'corrected' payload fails validation, we return error instead of crashing, test_verify_agree_roundtrip(), test_verify_corrected_returns_new_result(), test_verify_transport_error_becomes_error_verdict(), TestBuildJudgeUserPrompt, TestParseJudgeVerdict (+1 more)

### Community 54 - "Community 54"
Cohesion: 0.17
Nodes (6): FakeConnection, FakeEngine, FakeResult, test_embedding_gate_allows_only_complete_status(), test_embedding_gate_fails_closed_for_incomplete_status(), test_embedding_gate_fails_closed_without_state()

### Community 55 - "Community 55"
Cohesion: 0.17
Nodes (6): FakeConnection, FakeEngine, FakeResult, test_finish_embedding_index_build_updates_status(), test_start_embedding_index_build_creates_building_state(), test_update_embedding_index_progress_persists_counts()

### Community 56 - "Community 56"
Cohesion: 0.13
Nodes (8): Texts under 50 chars should never trigger correction., High ? ratio indicates unrecognized glyphs., Many single-char words indicate broken tokenization., High ratio of unusual characters., Normal single-char words (articles, abbreviations) shouldn't trigger., Just under 2% threshold should pass., Just over 2% threshold should trigger., TestTextLooksBroken

### Community 57 - "Community 57"
Cohesion: 0.13
Nodes (9): Tests for OllamaClient.embed() retry/truncation and chat_json() parsing., Retryability includes Pool/Write timeouts and protocol/read-write errors., unload_model(swap=True) waits for the configured swap delay., unload_model(swap=True) does not sleep when swap delay is 0., unload_model() without swap=True never sleeps (terminal cleanup)., test_is_retryable_covers_additional_transport_errors(), test_unload_model_no_sleep_without_swap(), test_unload_model_skips_sleep_when_zero() (+1 more)

### Community 58 - "Community 58"
Cohesion: 0.20
Nodes (14): ActorExecutionHandle, finish_actor_execution(), list_stale_running_actor_executions(), mark_stale_actor_execution_recovered(), Durable actor execution tracking helpers., Mark a durable actor execution row finished., Mark an actor execution retrying with durable backoff metadata., Return actor executions that were running before the recovery threshold.      Re (+6 more)

### Community 59 - "Community 59"
Cohesion: 0.25
Nodes (1): ChatController

### Community 60 - "Community 60"
Cohesion: 0.13
Nodes (3): Tests for OCR correction: heuristic, mode dispatch, vision, fallback, and cache., TestBatchCorrectDocuments, TestMaybeCorrectOcr

### Community 61 - "Community 61"
Cohesion: 0.21
Nodes (13): CommandRecord, _list_pending_commands(), list_pending_embedding_build_commands(), list_pending_poll_reconciliation_commands(), list_pending_reindex_commands(), mark_command_status(), Durable command helpers for event-driven recovery bridges., Return pending commands of one durable command type. (+5 more)

### Community 62 - "Community 62"
Cohesion: 0.21
Nodes (13): build_paperless_patch(), commit_review_suggestion_to_paperless(), list_review_suggestions_ready_to_commit(), load_review_commit(), mark_review_commit_status(), _optional_int(), Event-driven review commit helpers., Build safe Paperless PATCH fields from reviewed IDs only. (+5 more)

### Community 63 - "Community 63"
Cohesion: 0.27
Nodes (1): PaperlessEventWebhookController

### Community 64 - "Community 64"
Cohesion: 0.14
Nodes (1): WorkerJobRecoveryTest

### Community 65 - "Community 65"
Cohesion: 0.18
Nodes (4): FakeConnection, FakeEngine, FakeResult, test_store_review_suggestion_inserts_pending_laravel_review()

### Community 66 - "Community 66"
Cohesion: 0.15
Nodes (1): AdminSettingsTest

### Community 67 - "Community 67"
Cohesion: 0.28
Nodes (1): PipelineRunController

### Community 68 - "Community 68"
Cohesion: 0.35
Nodes (1): LegacyPythonState

### Community 69 - "Community 69"
Cohesion: 0.15
Nodes (7): Should include title and truncated content., Total output should be limited to embed_max_chars (default 6000)., Total truncation should respect a custom embed_max_chars value., A long title + content combo must not exceed embed_max_chars., A doc with only a title should still return the title., A doc with no title and no content should return empty string., TestDocumentSummary

### Community 70 - "Community 70"
Cohesion: 0.26
Nodes (11): _is_image(), _is_pdf(), page_count(), Render document files (PDF/image) to base64-encoded images for vision models., Convert a document file to a list of base64-encoded JPEG images.      For PDFs,, Render PDF pages to base64-encoded JPEG images., Resize an image if needed and return as base64-encoded JPEG., Return the number of pages in a document file. (+3 more)

### Community 71 - "Community 71"
Cohesion: 0.32
Nodes (11): default_prompt_path(), get_prompt_spec(), load_default_prompt(), load_prompt(), override_prompt_path(), prompt_payload(), PromptSpec, Editable system prompt storage helpers. (+3 more)

### Community 72 - "Community 72"
Cohesion: 0.39
Nodes (1): EntityApprovalController

### Community 74 - "Community 74"
Cohesion: 0.24
Nodes (11): check_cve_fix(), get_installed_packages(), get_release_date(), load_allowlist(), main(), _parse_version(), Query OSV.dev whether *version* of *name* fixes any known vulnerability.      Re, Load package==version pairs that are exempted from the age check. (+3 more)

### Community 75 - "Community 75"
Cohesion: 0.18
Nodes (8): _FakeLoop, Tests for the CLI poll --force flag., cmd_poll(force=True) passes force through to poll_inbox., main() parses --force and passes it to cmd_poll., main() passes force=False for poll when --force is not provided., test_cmd_poll_passes_force(), test_main_parses_force_flag_for_poll(), test_main_poll_no_force_flag()

### Community 76 - "Community 76"
Cohesion: 0.20
Nodes (10): _mock_conn_cm(), Tests for the CLI process-doc command., main() exits with error when process-doc is missing document id., cmd_process_doc(..., force=True) should clear processed_documents entry first., cmd_process_doc(..., force=False) should not delete from processed_documents., main() parses doc id and --force for process-doc., test_cmd_process_doc_force_deletes_processed_row(), test_cmd_process_doc_without_force_keeps_processed_row() (+2 more)

### Community 77 - "Community 77"
Cohesion: 0.20
Nodes (3): FakeConnection, FakeEngine, test_store_document_embedding_persists_pgvector()

### Community 78 - "Community 78"
Cohesion: 0.17
Nodes (7): Verify that blacklisted tags are excluded from whitelist insertion., A tag in the blacklist should be silently skipped by upsert_tag_proposal., Tags not in the blacklist should still be inserted normally., Blacklisted tag should not create or increment any whitelist entry., Tag rejected (in blacklist), then re-proposed by LLM, should stay blocked., Tag whitelist/blacklist checks should be case-insensitive., TestBlacklistFiltering

### Community 79 - "Community 79"
Cohesion: 0.35
Nodes (1): OcrReviewController

### Community 80 - "Community 80"
Cohesion: 0.36
Nodes (1): WebhookDeliveryController

### Community 81 - "Community 81"
Cohesion: 0.18
Nodes (1): PipelineRunControlTest

### Community 82 - "Community 82"
Cohesion: 0.18
Nodes (1): ReviewSuggestion

### Community 83 - "Community 83"
Cohesion: 0.33
Nodes (1): WorkerResultIngestor

### Community 84 - "Community 84"
Cohesion: 0.22
Nodes (3): CurrentUrlState, cn(), toUrl()

### Community 85 - "Community 85"
Cohesion: 0.18
Nodes (1): PaperlessEventWebhookTest

### Community 86 - "Community 86"
Cohesion: 0.18
Nodes (9): Tests for the CLI reindex-ocr --force flag., cmd_reindex_ocr(force=True) passes force through to batch_correct_documents., cmd_reindex_ocr() defaults to force=False., main() parses --force and passes it to cmd_reindex_ocr., main() passes force=False when --force is not given., test_cmd_reindex_ocr_default_no_force(), test_cmd_reindex_ocr_passes_force(), test_main_no_force_flag() (+1 more)

### Community 87 - "Community 87"
Cohesion: 0.24
Nodes (4): FakeConnection, FakeEngine, test_update_actor_execution_progress_persists_snapshot(), test_update_pipeline_run_progress_persists_snapshot()

### Community 88 - "Community 88"
Cohesion: 0.18
Nodes (3): Partial matches should NOT resolve — only exact., Leading/trailing whitespace means no match., TestResolveEntity

### Community 89 - "Community 89"
Cohesion: 0.29
Nodes (9): EmbeddingIndexBuild, finish_embedding_index_build(), Durable embedding-index state helpers., Mark an embedding-index build complete or failed., Create a durable embedding-index build row in `building` state., Persist restart-safe embedding build progress., sql_text(), start_embedding_index_build() (+1 more)

### Community 90 - "Community 90"
Cohesion: 0.29
Nodes (9): finish_pipeline_item(), PipelineItemRecord, progress_from_pipeline_items(), Durable pipeline item helpers., Create a running item row for a retry-safe pipeline step., Mark a pipeline item succeeded, failed or skipped., Derive progress counters from durable item state., sql_text() (+1 more)

### Community 91 - "Community 91"
Cohesion: 0.20
Nodes (9): classify_exception(), Retry classification contracts for event-driven actors., Return the bounded default backoff for a 1-based retry attempt., Return whether an actor should schedule another durable attempt., Classify common actor exceptions without logging sensitive payloads.      The cl, retry_backoff_seconds(), RetryClass, should_retry() (+1 more)

### Community 92 - "Community 92"
Cohesion: 0.29
Nodes (9): engine(), list_queued_webhook_delivery_ids(), load_webhook_delivery(), mark_webhook_delivery_status(), PostgreSQL helpers for webhook delivery actor state., Persist webhook actor outcome without storing document contents in logs., Load the normalized state needed by the webhook Dramatiq actor., Return queued webhook deliveries eligible for actor enqueue/recovery. (+1 more)

### Community 93 - "Community 93"
Cohesion: 0.33
Nodes (1): WebhookDeliveryControlTest

### Community 94 - "Community 94"
Cohesion: 0.29
Nodes (1): WorkerJobController

### Community 95 - "Community 95"
Cohesion: 0.31
Nodes (1): WorkerJobRecovery

### Community 96 - "Community 96"
Cohesion: 0.20
Nodes (1): OcrReviewTest

### Community 97 - "Community 97"
Cohesion: 0.20
Nodes (1): WorkerResultIngestorTest

### Community 98 - "Community 98"
Cohesion: 0.38
Nodes (7): _conn(), _prepare(), _progress_lines(), test_embedding_failure_reports_document_failed_and_continues(), test_embedding_timeout_reports_document_failed_and_continues(), test_huge_document_is_truncated_and_reports_guardrail(), test_normal_document_embeds()

### Community 99 - "Community 99"
Cohesion: 0.22
Nodes (1): MaintenanceTest

### Community 100 - "Community 100"
Cohesion: 0.31
Nodes (1): AuthenticationTest

### Community 101 - "Community 101"
Cohesion: 0.22
Nodes (1): ChatTest

### Community 102 - "Community 102"
Cohesion: 0.39
Nodes (1): HealthCheckController

### Community 103 - "Community 103"
Cohesion: 0.22
Nodes (1): StatsAndErrorsTest

### Community 105 - "Community 105"
Cohesion: 0.22
Nodes (1): PaperlessWebhookTest

### Community 107 - "Community 107"
Cohesion: 0.22
Nodes (5): Documents appearing in both lists should rank highest., Empty inputs should produce empty output., When FTS is empty, only vector results appear., vector_weight=1.0 should fully favour vector ranking., TestReciprocalRankFusion

### Community 108 - "Community 108"
Cohesion: 0.39
Nodes (8): _insert_entity_suggestion(), Tests for retroactive correspondent and document-type application on approval., test_retroactive_correspondent_patches_committed_doc_still_in_inbox(), test_retroactive_correspondent_resolves_pending_without_patch(), test_retroactive_correspondent_skips_committed_doc_without_inbox_tag(), test_retroactive_doctype_patches_committed_doc_still_in_inbox(), test_retroactive_doctype_resolves_pending_without_patch(), test_retroactive_doctype_skips_committed_doc_without_inbox_tag()

### Community 109 - "Community 109"
Cohesion: 0.36
Nodes (7): build_parser(), enqueue_poll_reconciliation(), main(), Event-driven worker bootstrap helpers.  This module is intentionally small: Dram, Enqueue polling reconciliation through the maintenance actor., Run durable recovery and periodic Paperless polling reconciliation., run_recovery_loop()

### Community 110 - "Community 110"
Cohesion: 0.36
Nodes (6): _json(), PostgreSQL helpers for Laravel review suggestion persistence., Persist a pending Laravel review suggestion from an event-driven actor., sql_text(), store_review_suggestion(), StoredReviewSuggestion

### Community 111 - "Community 111"
Cohesion: 0.25
Nodes (1): lang

### Community 112 - "Community 112"
Cohesion: 0.43
Nodes (1): PaperlessWebhookController

### Community 113 - "Community 113"
Cohesion: 0.43
Nodes (1): StatsController

### Community 114 - "Community 114"
Cohesion: 0.39
Nodes (1): FortifyServiceProvider

### Community 115 - "Community 115"
Cohesion: 0.43
Nodes (1): PythonRuntimeConfigExporter

### Community 119 - "Community 119"
Cohesion: 0.32
Nodes (1): WorkerJobCancellationTest

### Community 121 - "Community 121"
Cohesion: 0.29
Nodes (3): Persistent, safe, user-facing job event protocol.  These events are intentionall, Persist a safe job event and return its row id., record_event()

### Community 122 - "Community 122"
Cohesion: 0.38
Nodes (6): ensure_embedding_index_ready(), latest_embedding_index_status(), Embedding readiness gate contract., Return the newest durable embedding-index status from PostgreSQL., Return whether document processing may start.      Document processing is allowe, sql_text()

### Community 123 - "Community 123"
Cohesion: 0.52
Nodes (1): DashboardController

### Community 124 - "Community 124"
Cohesion: 0.48
Nodes (1): InboxController

### Community 125 - "Community 125"
Cohesion: 0.29
Nodes (1): HealthCheckTest

### Community 126 - "Community 126"
Cohesion: 0.29
Nodes (1): MaintenanceCommandTest

### Community 128 - "Community 128"
Cohesion: 0.29
Nodes (1): EntityApproval

### Community 129 - "Community 129"
Cohesion: 0.29
Nodes (1): PipelineRun

### Community 131 - "Community 131"
Cohesion: 0.52
Nodes (6): check_content_patterns(), check_expected_files(), check_graphify_portability(), main(), _portable_check_command(), _read_text()

### Community 132 - "Community 132"
Cohesion: 0.29
Nodes (1): TestResolveTags

### Community 133 - "Community 133"
Cohesion: 0.60
Nodes (5): _classify_document(), _fetch_paperless_document(), _handle_document_pipeline_impl(), run_async(), _update_item_derived_progress()

### Community 134 - "Community 134"
Cohesion: 0.40
Nodes (1): MaintenanceController

### Community 135 - "Community 135"
Cohesion: 0.33
Nodes (5): configure_broker(), queue_name(), Dramatiq broker configuration for the event-driven pipeline., Return a queue name with the configured Archibot prefix., Configure and return the Dramatiq RabbitMQ broker when Dramatiq is installed.

### Community 136 - "Community 136"
Cohesion: 0.33
Nodes (5): encode_path_segment(), escape_html(), Helpers for safely rendering small HTML fragments and user-visible errors., Escape a value for safe insertion into inline HTML fragments., Encode a value for use in URLs / DOM ids derived from path segments.

### Community 137 - "Community 137"
Cohesion: 0.53
Nodes (1): ErrorsController

### Community 138 - "Community 138"
Cohesion: 0.33
Nodes (1): SetupController

### Community 139 - "Community 139"
Cohesion: 0.33
Nodes (1): EntityApprovalTest

### Community 140 - "Community 140"
Cohesion: 0.33
Nodes (1): EmbeddingIndexControlTest

### Community 141 - "Community 141"
Cohesion: 0.33
Nodes (1): ChatSession

### Community 142 - "Community 142"
Cohesion: 0.33
Nodes (1): SetupState

### Community 143 - "Community 143"
Cohesion: 0.33
Nodes (1): PaperlessUser

### Community 144 - "Community 144"
Cohesion: 0.47
Nodes (1): TestCase

### Community 146 - "Community 146"
Cohesion: 0.33
Nodes (1): TestEffectiveOcrMode

### Community 147 - "Community 147"
Cohesion: 0.33
Nodes (1): TestSplitTextByPages

### Community 148 - "Community 148"
Cohesion: 0.50
Nodes (3): _commit_review_suggestion_impl(), Review and commit actors for accepted suggestions., run_async()

### Community 149 - "Community 149"
Cohesion: 0.50
Nodes (4): _handle_paperless_webhook_impl(), Webhook actors for the event-driven pipeline., Return whether a Paperless webhook should be tracked as automatic reprocess., webhook_requests_reprocess()

### Community 150 - "Community 150"
Cohesion: 0.50
Nodes (4): PipelineStartResult, Shared pipeline-start contract for webhook, poll, manual, retry and reindex trig, Start or attach to a document pipeline through the shared gate.      This is the, start_or_attach_document_pipeline()

### Community 151 - "Community 151"
Cohesion: 0.40
Nodes (1): LocalAccountManagementDisabledTest

### Community 152 - "Community 152"
Cohesion: 0.60
Nodes (1): EmbeddingIndexController

### Community 153 - "Community 153"
Cohesion: 0.40
Nodes (1): UserFactory

### Community 154 - "Community 154"
Cohesion: 0.40
Nodes (1): InboxTest

### Community 155 - "Community 155"
Cohesion: 0.40
Nodes (2): INPUT_OTP_CONTEXT, InputOTPContext

### Community 156 - "Community 156"
Cohesion: 0.40
Nodes (1): OcrReview

### Community 157 - "Community 157"
Cohesion: 0.40
Nodes (1): PipelineEvent

### Community 158 - "Community 158"
Cohesion: 0.40
Nodes (1): User

### Community 159 - "Community 159"
Cohesion: 0.50
Nodes (1): AppServiceProvider

### Community 160 - "Community 160"
Cohesion: 0.50
Nodes (1): SettingsCatalog

### Community 161 - "Community 161"
Cohesion: 0.40
Nodes (1): WorkerJobDispatcher

### Community 162 - "Community 162"
Cohesion: 0.40
Nodes (1): BuildInfo

### Community 163 - "Community 163"
Cohesion: 0.40
Nodes (1): EmbeddingsTest

### Community 164 - "Community 164"
Cohesion: 0.40
Nodes (3): If the document has no embedding, return empty list., Should return (doc_id, distance) tuples excluding source doc., TestFindSimilarById

### Community 168 - "Community 168"
Cohesion: 0.67
Nodes (1): AuditLogController

### Community 169 - "Community 169"
Cohesion: 0.50
Nodes (1): AuditLogsTest

### Community 170 - "Community 170"
Cohesion: 0.50
Nodes (1): lang

### Community 171 - "Community 171"
Cohesion: 0.50
Nodes (3): publish_pipeline_event(), PostgreSQL-backed pipeline event publishing helpers., Publish a pipeline event.      The first migration step keeps this helper intent

### Community 172 - "Community 172"
Cohesion: 0.50
Nodes (3): Runtime context helpers for actors., Return a stable-enough runtime worker id for logs and actor execution rows., worker_id()

### Community 173 - "Community 173"
Cohesion: 0.50
Nodes (1): Idempotency helpers for webhook and document pipeline starts.

### Community 174 - "Community 174"
Cohesion: 0.50
Nodes (1): Lock key helpers for event-driven pipeline coordination.

### Community 175 - "Community 175"
Cohesion: 0.50
Nodes (1): Document search, retrieval, and (opt-in) update tools.

### Community 176 - "Community 176"
Cohesion: 0.50
Nodes (1): Read-only tools for listing Paperless-NGX entities.

### Community 177 - "Community 177"
Cohesion: 0.50
Nodes (1): Suggestion listing and (opt-in) approval/rejection tools.

### Community 178 - "Community 178"
Cohesion: 0.50
Nodes (1): PythonChatRag

### Community 179 - "Community 179"
Cohesion: 0.67
Nodes (1): RecoverWorkerJobs

### Community 180 - "Community 180"
Cohesion: 0.50
Nodes (1): MaintenanceCommandController

### Community 181 - "Community 181"
Cohesion: 0.50
Nodes (1): DashboardTest

### Community 182 - "Community 182"
Cohesion: 0.50
Nodes (1): PipelineRunVisibilityTest

### Community 183 - "Community 183"
Cohesion: 0.50
Nodes (1): RunPythonWorkerJob

### Community 184 - "Community 184"
Cohesion: 0.67
Nodes (1): EnsureSetupIsComplete

### Community 185 - "Community 185"
Cohesion: 0.50
Nodes (1): HandleInertiaRequests

### Community 186 - "Community 186"
Cohesion: 0.50
Nodes (1): AppSetting

### Community 187 - "Community 187"
Cohesion: 0.50
Nodes (1): AuditLog

### Community 188 - "Community 188"
Cohesion: 0.50
Nodes (1): ChatMessage

### Community 189 - "Community 189"
Cohesion: 0.50
Nodes (1): Command

### Community 190 - "Community 190"
Cohesion: 0.50
Nodes (1): WorkerJobLog

### Community 191 - "Community 191"
Cohesion: 0.50
Nodes (1): OllamaClient

### Community 192 - "Community 192"
Cohesion: 0.67
Nodes (1): LegacySettingsImporter

### Community 193 - "Community 193"
Cohesion: 0.67
Nodes (1): EmbeddingIndexSnapshot

### Community 197 - "Community 197"
Cohesion: 0.67
Nodes (3): numericId(), PaperlessEntityOption, paperlessLabel()

### Community 198 - "Community 198"
Cohesion: 0.50
Nodes (1): lang

### Community 199 - "Community 199"
Cohesion: 0.50
Nodes (1): lang

### Community 200 - "Community 200"
Cohesion: 0.83
Nodes (3): is_external(), iter_markdown_files(), main()

### Community 204 - "Community 204"
Cohesion: 0.50
Nodes (1): TestOcrResponseParsing

### Community 207 - "Community 207"
Cohesion: 0.67
Nodes (1): AuditPruneCommandTest

### Community 208 - "Community 208"
Cohesion: 0.67
Nodes (1): AI classification tools — rate-limited, inbox-only.

### Community 209 - "Community 209"
Cohesion: 0.67
Nodes (1): Correspondent whitelist and blacklist tools — listing, approval, and blacklist m

### Community 210 - "Community 210"
Cohesion: 0.67
Nodes (1): Document type whitelist and blacklist tools — listing, approval, and blacklist m

### Community 211 - "Community 211"
Cohesion: 0.67
Nodes (1): MCP resources providing read-only summaries.  Note: FastMCP resources cannot rec

### Community 212 - "Community 212"
Cohesion: 0.67
Nodes (1): System status and health check tool.

### Community 213 - "Community 213"
Cohesion: 0.67
Nodes (1): Tag whitelist and blacklist tools — listing, approval, and blacklist management.

### Community 214 - "Community 214"
Cohesion: 0.67
Nodes (1): ChatRagResult

### Community 215 - "Community 215"
Cohesion: 0.67
Nodes (1): ArchibotReset

### Community 216 - "Community 216"
Cohesion: 0.67
Nodes (1): CancelStaleWorkerJobs

### Community 217 - "Community 217"
Cohesion: 0.67
Nodes (1): PruneAuditLogs

### Community 218 - "Community 218"
Cohesion: 0.67
Nodes (1): ResetSetup

### Community 219 - "Community 219"
Cohesion: 0.67
Nodes (1): EmbeddingsController

### Community 220 - "Community 220"
Cohesion: 0.67
Nodes (2): DIALOG_CONTEXT, DialogContext

### Community 221 - "Community 221"
Cohesion: 0.67
Nodes (2): DROPDOWN_MENU_CONTEXT, DropdownMenuContext

### Community 222 - "Community 222"
Cohesion: 0.67
Nodes (1): EntityApprovalFactory

### Community 223 - "Community 223"
Cohesion: 0.67
Nodes (1): ReviewSuggestionFactory

### Community 224 - "Community 224"
Cohesion: 0.67
Nodes (1): WorkerJobFactory

### Community 225 - "Community 225"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 226 - "Community 226"
Cohesion: 0.67
Nodes (1): HandleAppearance

### Community 227 - "Community 227"
Cohesion: 0.67
Nodes (1): ActorExecution

### Community 228 - "Community 228"
Cohesion: 0.67
Nodes (1): DocumentEmbedding

### Community 229 - "Community 229"
Cohesion: 0.67
Nodes (1): EmbeddingIndexState

### Community 230 - "Community 230"
Cohesion: 0.67
Nodes (1): PipelineItem

### Community 231 - "Community 231"
Cohesion: 0.67
Nodes (1): WebhookDelivery

### Community 232 - "Community 232"
Cohesion: 0.67
Nodes (1): CompleteSetup

### Community 233 - "Community 233"
Cohesion: 0.67
Nodes (1): StaleWorkerJobCanceller

### Community 255 - "Community 255"
Cohesion: 0.67
Nodes (1): DatabaseSeeder

### Community 256 - "Community 256"
Cohesion: 0.67
Nodes (2): controlStatements, paddingAroundControl

### Community 257 - "Community 257"
Cohesion: 0.67
Nodes (2): SHEET_CONTEXT, SheetContext

### Community 258 - "Community 258"
Cohesion: 0.67
Nodes (1): InitialsApi

### Community 259 - "Community 259"
Cohesion: 0.67
Nodes (1): LegacySettingsImportTest

### Community 260 - "Community 260"
Cohesion: 0.67
Nodes (1): ExampleTest

### Community 261 - "Community 261"
Cohesion: 0.67
Nodes (2): Multiple context docs with varying metadata produce correct prompt., TestFullPromptWithContext

### Community 262 - "Community 262"
Cohesion: 0.67
Nodes (2): store_embedding should write to doc_embeddings, doc_embedding_meta, and doc_fts., TestStoreEmbedding

### Community 263 - "Community 263"
Cohesion: 0.67
Nodes (2): max_distance=0 should mean no distance threshold is applied., TestDistanceThreshold

### Community 264 - "Community 264"
Cohesion: 0.67
Nodes (2): Cache stores and retrieves corrected text., TestOcrCache

### Community 265 - "Community 265"
Cohesion: 1.00
Nodes (1): Dramatiq actor package for the event-driven Archibot pipeline.

### Community 266 - "Community 266"
Cohesion: 1.00
Nodes (1): lang

### Community 267 - "Community 267"
Cohesion: 1.00
Nodes (1): lang

### Community 268 - "Community 268"
Cohesion: 1.00
Nodes (1): Event helpers for the event-driven Archibot pipeline.

### Community 269 - "Community 269"
Cohesion: 1.00
Nodes (1): Canonical event names for the event-driven pipeline.

### Community 270 - "Community 270"
Cohesion: 1.00
Nodes (1): ArchiBot — AI classifier for Paperless-NGX.

### Community 271 - "Community 271"
Cohesion: 1.00
Nodes (1): Shared job helpers for Dramatiq actors.

### Community 272 - "Community 272"
Cohesion: 1.00
Nodes (1): lang

### Community 275 - "Community 275"
Cohesion: 1.00
Nodes (1): Controller

### Community 276 - "Community 276"
Cohesion: 1.00
Nodes (1): lang

### Community 277 - "Community 277"
Cohesion: 1.00
Nodes (1): lang

### Community 278 - "Community 278"
Cohesion: 1.00
Nodes (1): lang

### Community 279 - "Community 279"
Cohesion: 1.00
Nodes (1): LlmCall

### Community 290 - "Community 290"
Cohesion: 1.00
Nodes (1): lang

### Community 291 - "Community 291"
Cohesion: 1.00
Nodes (1): lang

### Community 292 - "Community 292"
Cohesion: 1.00
Nodes (1): lang

### Community 293 - "Community 293"
Cohesion: 1.00
Nodes (1): lang

### Community 294 - "Community 294"
Cohesion: 1.00
Nodes (1): lang

### Community 295 - "Community 295"
Cohesion: 1.00
Nodes (1): lang

### Community 296 - "Community 296"
Cohesion: 1.00
Nodes (1): lang

### Community 297 - "Community 297"
Cohesion: 1.00
Nodes (1): lang

### Community 298 - "Community 298"
Cohesion: 1.00
Nodes (1): lang

### Community 299 - "Community 299"
Cohesion: 1.00
Nodes (1): lang

### Community 300 - "Community 300"
Cohesion: 1.00
Nodes (1): lang

### Community 301 - "Community 301"
Cohesion: 1.00
Nodes (1): lang

### Community 304 - "Community 304"
Cohesion: 1.00
Nodes (2): Multiple context-length errors cause progressive truncation., test_embed_context_length_progressive_truncation()

### Community 305 - "Community 305"
Cohesion: 1.00
Nodes (2): With retries=0, errors raise immediately with provider body., test_embed_retry_disabled_when_zero()

### Community 306 - "Community 306"
Cohesion: 1.00
Nodes (2): ReadTimeout should be retried once for chat JSON requests., test_chat_json_retries_on_read_timeout()

### Community 307 - "Community 307"
Cohesion: 1.00
Nodes (2): The payload sent to Ollama includes num_ctx in options., test_chat_json_passes_num_ctx()

### Community 308 - "Community 308"
Cohesion: 1.00
Nodes (2): Explicit num_ctx override takes precedence over settings default., test_chat_json_passes_custom_num_ctx()

### Community 309 - "Community 309"
Cohesion: 1.00
Nodes (2): chat_json retries once on transient 500 and then succeeds., test_chat_json_retries_on_transient_500()

### Community 310 - "Community 310"
Cohesion: 1.00
Nodes (2): Plain chat retries on transient transport errors., test_chat_retries_on_connect_error()

### Community 311 - "Community 311"
Cohesion: 1.00
Nodes (2): Vision chat uses settings.ollama_num_ctx when no override is given., test_chat_vision_json_passes_default_num_ctx()

### Community 312 - "Community 312"
Cohesion: 1.00
Nodes (2): Verify process_document returns the correct ProcessResult., TestProcessDocumentReturn

### Community 313 - "Community 313"
Cohesion: 1.00
Nodes (2): Verify poll_inbox logs a summary with correct counters., TestPollCycleSummary

### Community 314 - "Community 314"
Cohesion: 1.00
Nodes (2): Tests for the embedding phase., TestPhaseEmbed

### Community 315 - "Community 315"
Cohesion: 1.00
Nodes (2): Tests for the OCR correction phase., TestPhaseOcr

### Community 316 - "Community 316"
Cohesion: 1.00
Nodes (2): Tests for the classification phase., TestPhaseClassify

### Community 317 - "Community 317"
Cohesion: 1.00
Nodes (2): Tests for the maybe_run_judge gate and wiring., TestMaybeRunJudge

### Community 318 - "Community 318"
Cohesion: 1.00
Nodes (2): Integration tests for the full phased poll_inbox flow., TestPhasedPollInbox

### Community 320 - "Community 320"
Cohesion: 1.00
Nodes (1): Treat empty env values for typed settings as unset.          Docker Compose/.env

### Community 321 - "Community 321"
Cohesion: 1.00
Nodes (1): Named AI provider profiles, always including the default profile.          `ai_p

### Community 322 - "Community 322"
Cohesion: 1.00
Nodes (1): Expected embedding vector dimension.          `ollama_embed_dim=0` enables auto

### Community 324 - "Community 324"
Cohesion: 1.00
Nodes (1): Accept common loose tag outputs from LLMs.          Normal form is a list of obj

### Community 326 - "Community 326"
Cohesion: 1.00
Nodes (1): Harden a chat payload for JSON-recovery retries.          Used after malformed J

### Community 327 - "Community 327"
Cohesion: 1.00
Nodes (1): Check if a 500 response is caused by input exceeding the context length.

### Community 328 - "Community 328"
Cohesion: 1.00
Nodes (1): Exponential backoff with jitter for retry attempt ``attempt``.

### Community 329 - "Community 329"
Cohesion: 1.00
Nodes (1): Parse JSON content, handling occasional markdown fence wrappers.

### Community 380 - "Community 380"
Cohesion: 1.00
Nodes (1): Entity lists should be fetched once per session, not per message.

### Community 381 - "Community 381"
Cohesion: 1.00
Nodes (1): Context blocks should use dynamic token budgeting, not hard-coded 2000 chars.

### Community 382 - "Community 382"
Cohesion: 1.00
Nodes (1): Ollama failures must not raise — caller keeps the initial result.

### Community 383 - "Community 383"
Cohesion: 1.00
Nodes (1): If there aren't enough candidates, return what we have.

### Community 384 - "Community 384"
Cohesion: 1.00
Nodes (1): A target doc with empty content should return no context.

### Community 385 - "Community 385"
Cohesion: 1.00
Nodes (1): Results should include distance scores from the vector search.

### Community 386 - "Community 386"
Cohesion: 1.00
Nodes (1): KNN search should exclude the source document.

### Community 387 - "Community 387"
Cohesion: 1.00
Nodes (1): find_similar_documents should return the same docs (without distances).

### Community 388 - "Community 388"
Cohesion: 1.00
Nodes (1): Should use the provided embedding without calling ollama.embed().

### Community 389 - "Community 389"
Cohesion: 1.00
Nodes (1): exclude_id should be passed to the KNN search.

### Community 390 - "Community 390"
Cohesion: 1.00
Nodes (1): Should return empty list when KNN returns no hits.

### Community 391 - "Community 391"
Cohesion: 1.00
Nodes (1): Documents without the inbox tag should be included as context.

### Community 392 - "Community 392"
Cohesion: 1.00
Nodes (1): Context docs beyond max_distance should be excluded.

### Community 393 - "Community 393"
Cohesion: 1.00
Nodes (1): When FTS returns no results, hybrid search uses pure vector.

### Community 394 - "Community 394"
Cohesion: 1.00
Nodes (1): If KNN returns no results, return empty list.

### Community 395 - "Community 395"
Cohesion: 1.00
Nodes (1): Results should not exceed the requested limit.

### Community 396 - "Community 396"
Cohesion: 1.00
Nodes (1): Text mode should call chat_json with model=ollama.ocr_model.

### Community 397 - "Community 397"
Cohesion: 1.00
Nodes (1): Text mode should skip correction when text looks fine.

### Community 398 - "Community 398"
Cohesion: 1.00
Nodes (1): vision_light without paperless client should fall back to text mode.

### Community 399 - "Community 399"
Cohesion: 1.00
Nodes (1): vision_full should run even when text looks fine (no heuristic gate).

### Community 400 - "Community 400"
Cohesion: 1.00
Nodes (1): Text mode should pass ollama_ocr_num_ctx to chat_json.

### Community 401 - "Community 401"
Cohesion: 1.00
Nodes (1): vision_full should pass ollama_ocr_num_ctx to chat_vision_json.

### Community 402 - "Community 402"
Cohesion: 1.00
Nodes (1): batch_correct_documents should fetch documents from Paperless API,         not f

### Community 403 - "Community 403"
Cohesion: 1.00
Nodes (1): Documents already in doc_ocr_cache should be skipped (force=False).

### Community 404 - "Community 404"
Cohesion: 1.00
Nodes (1): With force=True, even cached documents should be processed.

### Community 405 - "Community 405"
Cohesion: 1.00
Nodes (1): When OCR mode is off, should return 0 without calling Paperless.

### Community 406 - "Community 406"
Cohesion: 1.00
Nodes (1): A document already in processed_documents (matching timestamp, non-error) return

### Community 407 - "Community 407"
Cohesion: 1.00
Nodes (1): The debug log for skipped documents includes the stored status.

### Community 408 - "Community 408"
Cohesion: 1.00
Nodes (1): When all docs are already processed, summary shows all skipped.

### Community 409 - "Community 409"
Cohesion: 1.00
Nodes (1): poll_inbox(force=True) should bypass idempotency skip checks.

### Community 410 - "Community 410"
Cohesion: 1.00
Nodes (1): Each document should be embedded exactly once (not twice like before).

### Community 411 - "Community 411"
Cohesion: 1.00
Nodes (1): If embedding fails for a doc, it gets None embedding + empty similar_results.

### Community 412 - "Community 412"
Cohesion: 1.00
Nodes (1): The embed model should be unloaded at the end of the phase.

### Community 413 - "Community 413"
Cohesion: 1.00
Nodes (1): OCR phase should update document content when corrections are made.

### Community 414 - "Community 414"
Cohesion: 1.00
Nodes (1): When OCR is disabled, the phase should return docs unchanged.

### Community 415 - "Community 415"
Cohesion: 1.00
Nodes (1): If OCR fails for a doc, original content should be preserved.

### Community 416 - "Community 416"
Cohesion: 1.00
Nodes (1): Classification should use similar_results from the embedding phase.

### Community 417 - "Community 417"
Cohesion: 1.00
Nodes (1): The classify model should be unloaded at the end of the phase.

### Community 418 - "Community 418"
Cohesion: 1.00
Nodes (1): If classify() succeeds but a later step raises, we must not record         anoth

### Community 419 - "Community 419"
Cohesion: 1.00
Nodes (1): Even if classification fails, a pre-computed embedding should be stored.

### Community 420 - "Community 420"
Cohesion: 1.00
Nodes (1): All embed() calls should happen before any chat_json() calls.

## Knowledge Gaps
- **414 isolated node(s):** `ArchiBot — AI classifier for Paperless-NGX.`, `Dramatiq actor package for the event-driven Archibot pipeline.`, `Webhook actors for the event-driven pipeline.`, `Return whether a Paperless webhook should be tracked as automatic reprocess.`, `Shared JSON-facing data builders for legacy Python API compatibility.` (+409 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Community 11`** (1 nodes): `WorkerJob`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 19`** (1 nodes): `PaperlessClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 24`** (1 nodes): `ReviewSuggestionTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 27`** (1 nodes): `ReviewSuggestionController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 32`** (1 nodes): `PythonWorkerCommand`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 38`** (1 nodes): `WorkerJobTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 39`** (1 nodes): `FirstRunSetupTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 40`** (1 nodes): `PythonWorkerCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 47`** (1 nodes): `SettingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 50`** (1 nodes): `Tests for Laravel worker JSON CLI contract.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 59`** (1 nodes): `ChatController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 63`** (1 nodes): `PaperlessEventWebhookController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 64`** (1 nodes): `WorkerJobRecoveryTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 66`** (1 nodes): `AdminSettingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 67`** (1 nodes): `PipelineRunController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 68`** (1 nodes): `LegacyPythonState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 72`** (1 nodes): `EntityApprovalController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 79`** (1 nodes): `OcrReviewController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 80`** (1 nodes): `WebhookDeliveryController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 81`** (1 nodes): `PipelineRunControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 82`** (1 nodes): `ReviewSuggestion`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 83`** (1 nodes): `WorkerResultIngestor`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 85`** (1 nodes): `PaperlessEventWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 93`** (1 nodes): `WebhookDeliveryControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 94`** (1 nodes): `WorkerJobController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 95`** (1 nodes): `WorkerJobRecovery`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 96`** (1 nodes): `OcrReviewTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 97`** (1 nodes): `WorkerResultIngestorTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 99`** (1 nodes): `MaintenanceTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 100`** (1 nodes): `AuthenticationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 101`** (1 nodes): `ChatTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 102`** (1 nodes): `HealthCheckController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 103`** (1 nodes): `StatsAndErrorsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 105`** (1 nodes): `PaperlessWebhookTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 111`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 112`** (1 nodes): `PaperlessWebhookController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 113`** (1 nodes): `StatsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 114`** (1 nodes): `FortifyServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 115`** (1 nodes): `PythonRuntimeConfigExporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 119`** (1 nodes): `WorkerJobCancellationTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 123`** (1 nodes): `DashboardController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 124`** (1 nodes): `InboxController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 125`** (1 nodes): `HealthCheckTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 126`** (1 nodes): `MaintenanceCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 128`** (1 nodes): `EntityApproval`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 129`** (1 nodes): `PipelineRun`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 132`** (1 nodes): `TestResolveTags`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 134`** (1 nodes): `MaintenanceController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 137`** (1 nodes): `ErrorsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 138`** (1 nodes): `SetupController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 139`** (1 nodes): `EntityApprovalTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 140`** (1 nodes): `EmbeddingIndexControlTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 141`** (1 nodes): `ChatSession`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 142`** (1 nodes): `SetupState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 143`** (1 nodes): `PaperlessUser`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 144`** (1 nodes): `TestCase`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 146`** (1 nodes): `TestEffectiveOcrMode`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 147`** (1 nodes): `TestSplitTextByPages`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 151`** (1 nodes): `LocalAccountManagementDisabledTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 152`** (1 nodes): `EmbeddingIndexController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 153`** (1 nodes): `UserFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 154`** (1 nodes): `InboxTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 155`** (2 nodes): `INPUT_OTP_CONTEXT`, `InputOTPContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 156`** (1 nodes): `OcrReview`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 157`** (1 nodes): `PipelineEvent`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 158`** (1 nodes): `User`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 159`** (1 nodes): `AppServiceProvider`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 160`** (1 nodes): `SettingsCatalog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 161`** (1 nodes): `WorkerJobDispatcher`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 162`** (1 nodes): `BuildInfo`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 163`** (1 nodes): `EmbeddingsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 168`** (1 nodes): `AuditLogController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 169`** (1 nodes): `AuditLogsTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 170`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 173`** (1 nodes): `Idempotency helpers for webhook and document pipeline starts.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 174`** (1 nodes): `Lock key helpers for event-driven pipeline coordination.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 175`** (1 nodes): `Document search, retrieval, and (opt-in) update tools.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 176`** (1 nodes): `Read-only tools for listing Paperless-NGX entities.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 177`** (1 nodes): `Suggestion listing and (opt-in) approval/rejection tools.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 178`** (1 nodes): `PythonChatRag`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 179`** (1 nodes): `RecoverWorkerJobs`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 180`** (1 nodes): `MaintenanceCommandController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 181`** (1 nodes): `DashboardTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 182`** (1 nodes): `PipelineRunVisibilityTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 183`** (1 nodes): `RunPythonWorkerJob`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 184`** (1 nodes): `EnsureSetupIsComplete`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 185`** (1 nodes): `HandleInertiaRequests`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 186`** (1 nodes): `AppSetting`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 187`** (1 nodes): `AuditLog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 188`** (1 nodes): `ChatMessage`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 189`** (1 nodes): `Command`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 190`** (1 nodes): `WorkerJobLog`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 191`** (1 nodes): `OllamaClient`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 192`** (1 nodes): `LegacySettingsImporter`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 193`** (1 nodes): `EmbeddingIndexSnapshot`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 198`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 199`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 204`** (1 nodes): `TestOcrResponseParsing`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 207`** (1 nodes): `AuditPruneCommandTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 208`** (1 nodes): `AI classification tools — rate-limited, inbox-only.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 209`** (1 nodes): `Correspondent whitelist and blacklist tools — listing, approval, and blacklist m`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 210`** (1 nodes): `Document type whitelist and blacklist tools — listing, approval, and blacklist m`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 211`** (1 nodes): `MCP resources providing read-only summaries.  Note: FastMCP resources cannot rec`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 212`** (1 nodes): `System status and health check tool.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 213`** (1 nodes): `Tag whitelist and blacklist tools — listing, approval, and blacklist management.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 214`** (1 nodes): `ChatRagResult`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 215`** (1 nodes): `ArchibotReset`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 216`** (1 nodes): `CancelStaleWorkerJobs`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 217`** (1 nodes): `PruneAuditLogs`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 218`** (1 nodes): `ResetSetup`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 219`** (1 nodes): `EmbeddingsController`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 220`** (2 nodes): `DIALOG_CONTEXT`, `DialogContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 221`** (2 nodes): `DROPDOWN_MENU_CONTEXT`, `DropdownMenuContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 222`** (1 nodes): `EntityApprovalFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 223`** (1 nodes): `ReviewSuggestionFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 224`** (1 nodes): `WorkerJobFactory`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 225`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 226`** (1 nodes): `HandleAppearance`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 227`** (1 nodes): `ActorExecution`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 228`** (1 nodes): `DocumentEmbedding`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 229`** (1 nodes): `EmbeddingIndexState`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 230`** (1 nodes): `PipelineItem`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 231`** (1 nodes): `WebhookDelivery`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 232`** (1 nodes): `CompleteSetup`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 233`** (1 nodes): `StaleWorkerJobCanceller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 255`** (1 nodes): `DatabaseSeeder`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 256`** (2 nodes): `controlStatements`, `paddingAroundControl`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 257`** (2 nodes): `SHEET_CONTEXT`, `SheetContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 258`** (1 nodes): `InitialsApi`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 259`** (1 nodes): `LegacySettingsImportTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 260`** (1 nodes): `ExampleTest`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 261`** (2 nodes): `Multiple context docs with varying metadata produce correct prompt.`, `TestFullPromptWithContext`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 262`** (2 nodes): `store_embedding should write to doc_embeddings, doc_embedding_meta, and doc_fts.`, `TestStoreEmbedding`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 263`** (2 nodes): `max_distance=0 should mean no distance threshold is applied.`, `TestDistanceThreshold`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 264`** (2 nodes): `Cache stores and retrieves corrected text.`, `TestOcrCache`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 265`** (1 nodes): `Dramatiq actor package for the event-driven Archibot pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 266`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 267`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 268`** (1 nodes): `Event helpers for the event-driven Archibot pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 269`** (1 nodes): `Canonical event names for the event-driven pipeline.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 270`** (1 nodes): `ArchiBot — AI classifier for Paperless-NGX.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 271`** (1 nodes): `Shared job helpers for Dramatiq actors.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 272`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 275`** (1 nodes): `Controller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 276`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 277`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 278`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 279`** (1 nodes): `LlmCall`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 290`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 291`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 292`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 293`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 294`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 295`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 296`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 297`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 298`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 299`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 300`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 301`** (1 nodes): `lang`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 304`** (2 nodes): `Multiple context-length errors cause progressive truncation.`, `test_embed_context_length_progressive_truncation()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 305`** (2 nodes): `With retries=0, errors raise immediately with provider body.`, `test_embed_retry_disabled_when_zero()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 306`** (2 nodes): `ReadTimeout should be retried once for chat JSON requests.`, `test_chat_json_retries_on_read_timeout()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 307`** (2 nodes): `The payload sent to Ollama includes num_ctx in options.`, `test_chat_json_passes_num_ctx()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 308`** (2 nodes): `Explicit num_ctx override takes precedence over settings default.`, `test_chat_json_passes_custom_num_ctx()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 309`** (2 nodes): `chat_json retries once on transient 500 and then succeeds.`, `test_chat_json_retries_on_transient_500()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 310`** (2 nodes): `Plain chat retries on transient transport errors.`, `test_chat_retries_on_connect_error()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 311`** (2 nodes): `Vision chat uses settings.ollama_num_ctx when no override is given.`, `test_chat_vision_json_passes_default_num_ctx()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 312`** (2 nodes): `Verify process_document returns the correct ProcessResult.`, `TestProcessDocumentReturn`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 313`** (2 nodes): `Verify poll_inbox logs a summary with correct counters.`, `TestPollCycleSummary`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 314`** (2 nodes): `Tests for the embedding phase.`, `TestPhaseEmbed`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 315`** (2 nodes): `Tests for the OCR correction phase.`, `TestPhaseOcr`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 316`** (2 nodes): `Tests for the classification phase.`, `TestPhaseClassify`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 317`** (2 nodes): `Tests for the maybe_run_judge gate and wiring.`, `TestMaybeRunJudge`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 318`** (2 nodes): `Integration tests for the full phased poll_inbox flow.`, `TestPhasedPollInbox`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 320`** (1 nodes): `Treat empty env values for typed settings as unset.          Docker Compose/.env`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 321`** (1 nodes): `Named AI provider profiles, always including the default profile.          `ai_p`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 322`** (1 nodes): `Expected embedding vector dimension.          `ollama_embed_dim=0` enables auto`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 324`** (1 nodes): `Accept common loose tag outputs from LLMs.          Normal form is a list of obj`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 326`** (1 nodes): `Harden a chat payload for JSON-recovery retries.          Used after malformed J`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 327`** (1 nodes): `Check if a 500 response is caused by input exceeding the context length.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 328`** (1 nodes): `Exponential backoff with jitter for retry attempt ``attempt``.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 329`** (1 nodes): `Parse JSON content, handling occasional markdown fence wrappers.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 380`** (1 nodes): `Entity lists should be fetched once per session, not per message.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 381`** (1 nodes): `Context blocks should use dynamic token budgeting, not hard-coded 2000 chars.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 382`** (1 nodes): `Ollama failures must not raise — caller keeps the initial result.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 383`** (1 nodes): `If there aren't enough candidates, return what we have.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 384`** (1 nodes): `A target doc with empty content should return no context.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 385`** (1 nodes): `Results should include distance scores from the vector search.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 386`** (1 nodes): `KNN search should exclude the source document.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 387`** (1 nodes): `find_similar_documents should return the same docs (without distances).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 388`** (1 nodes): `Should use the provided embedding without calling ollama.embed().`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 389`** (1 nodes): `exclude_id should be passed to the KNN search.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 390`** (1 nodes): `Should return empty list when KNN returns no hits.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 391`** (1 nodes): `Documents without the inbox tag should be included as context.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 392`** (1 nodes): `Context docs beyond max_distance should be excluded.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 393`** (1 nodes): `When FTS returns no results, hybrid search uses pure vector.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 394`** (1 nodes): `If KNN returns no results, return empty list.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 395`** (1 nodes): `Results should not exceed the requested limit.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 396`** (1 nodes): `Text mode should call chat_json with model=ollama.ocr_model.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 397`** (1 nodes): `Text mode should skip correction when text looks fine.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 398`** (1 nodes): `vision_light without paperless client should fall back to text mode.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 399`** (1 nodes): `vision_full should run even when text looks fine (no heuristic gate).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 400`** (1 nodes): `Text mode should pass ollama_ocr_num_ctx to chat_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 401`** (1 nodes): `vision_full should pass ollama_ocr_num_ctx to chat_vision_json.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 402`** (1 nodes): `batch_correct_documents should fetch documents from Paperless API,         not f`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 403`** (1 nodes): `Documents already in doc_ocr_cache should be skipped (force=False).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 404`** (1 nodes): `With force=True, even cached documents should be processed.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 405`** (1 nodes): `When OCR mode is off, should return 0 without calling Paperless.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 406`** (1 nodes): `A document already in processed_documents (matching timestamp, non-error) return`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 407`** (1 nodes): `The debug log for skipped documents includes the stored status.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 408`** (1 nodes): `When all docs are already processed, summary shows all skipped.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 409`** (1 nodes): `poll_inbox(force=True) should bypass idempotency skip checks.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 410`** (1 nodes): `Each document should be embedded exactly once (not twice like before).`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 411`** (1 nodes): `If embedding fails for a doc, it gets None embedding + empty similar_results.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 412`** (1 nodes): `The embed model should be unloaded at the end of the phase.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 413`** (1 nodes): `OCR phase should update document content when corrections are made.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 414`** (1 nodes): `When OCR is disabled, the phase should return docs unchanged.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 415`** (1 nodes): `If OCR fails for a doc, original content should be preserved.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 416`** (1 nodes): `Classification should use similar_results from the embedding phase.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 417`** (1 nodes): `The classify model should be unloaded at the end of the phase.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 418`** (1 nodes): `If classify() succeeds but a later step raises, we must not record         anoth`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 419`** (1 nodes): `Even if classification fails, a pre-computed embedding should be stored.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 420`** (1 nodes): `All embed() calls should happen before any chat_json() calls.`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `PaperlessClient` connect `Community 3` to `Community 0`, `Community 2`, `Community 10`, `Community 148`, `Community 12`, `Community 1`, `Community 48`, `Community 62`, `Community 7`, `Community 52`, `Community 9`, `Community 5`, `Community 16`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **Why does `OllamaClient` connect `Community 2` to `Community 4`, `Community 10`, `Community 3`, `Community 12`, `Community 1`, `Community 48`, `Community 7`, `Community 0`, `Community 9`, `Community 5`, `Community 16`?**
  _High betweenness centrality (0.015) - this node is a cross-community bridge._
- **Why does `PaperlessDocument` connect `Community 0` to `Community 3`, `Community 2`, `Community 9`?**
  _High betweenness centrality (0.007) - this node is a cross-community bridge._
- **Are the 148 inferred relationships involving `PaperlessClient` (e.g. with `Document processing actors for the event-driven pipeline.` and `Handle one document pipeline run through durable event-driven steps.      This a`) actually correct?**
  _`PaperlessClient` has 148 INFERRED edges - model-reasoned connections that need verification._
- **Are the 143 inferred relationships involving `OllamaClient` (e.g. with `Document processing actors for the event-driven pipeline.` and `Handle one document pipeline run through durable event-driven steps.      This a`) actually correct?**
  _`OllamaClient` has 143 INFERRED edges - model-reasoned connections that need verification._
- **Are the 112 inferred relationships involving `PaperlessDocument` (e.g. with `PaperlessClient` and `Paperless-NGX REST API client.`) actually correct?**
  _`PaperlessDocument` has 112 INFERRED edges - model-reasoned connections that need verification._
- **Are the 86 inferred relationships involving `SuggestionRow` (e.g. with `CLI management commands for manual pipeline triggering.  Usage::      python -m` and `Set up structlog for CLI use (always console renderer).`) actually correct?**
  _`SuggestionRow` has 86 INFERRED edges - model-reasoned connections that need verification._