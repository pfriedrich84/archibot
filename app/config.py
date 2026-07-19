"""Application configuration via pydantic-settings (.env-driven)."""

from __future__ import annotations

from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

from pydantic import AliasChoices, Field, ValidationInfo, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


def require_postgresql_database_url(value: str) -> str:
    """Return a PostgreSQL URL or fail closed before any product DB is opened."""
    scheme = urlsplit(value.strip()).scheme.lower()
    if scheme != "postgresql" and not scheme.startswith("postgresql+"):
        raise ValueError("DATABASE_URL must use PostgreSQL; other database backends are test-only")
    return value


class Settings(BaseSettings):
    """All runtime settings. Everything is driven from environment variables."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        populate_by_name=True,
    )

    # --- Paperless ---
    paperless_url: str = ""
    paperless_token: str = ""
    paperless_inbox_tag_id: int = 0
    paperless_processed_tag_id: int | None = None
    keep_inbox_tag: bool = True

    # --- AI provider / Ollama-compatible defaults ---
    llm_provider: str = "ollama"  # ollama | openai_compatible
    ollama_url: str = Field(
        default="http://ollama:11434",
        validation_alias=AliasChoices("OPENAI_BASE_URL", "OLLAMA_URL"),
    )
    openai_api_key: str = ""
    ollama_model: str = Field(
        default="gemma4:e4b",
        validation_alias=AliasChoices("CLASSIFICATION_MODEL", "OLLAMA_MODEL"),
    )
    ollama_embed_model: str = Field(
        default="qwen3-embedding:4b",
        validation_alias=AliasChoices(
            "ARCHIBOT_EMBEDDING_MODEL", "EMBEDDING_MODEL", "OLLAMA_EMBED_MODEL"
        ),
    )
    ollama_embed_dim: int = Field(
        default=0,
        validation_alias=AliasChoices("EMBEDDING_DIMENSION", "OLLAMA_EMBED_DIM"),
    )  # 0 = auto-detect from known model defaults
    ollama_ocr_model: str = Field(
        default="qwen3:4b",
        validation_alias=AliasChoices("OCR_TEXT_MODEL", "OCR_MODEL", "OLLAMA_OCR_MODEL"),
    )
    ollama_timeout_seconds: int = 600
    ollama_embed_retries: int = 3
    ollama_embed_retry_base_delay: float = 1.0
    ollama_chat_retries: int = 2
    ollama_chat_retry_base_delay: float = 1.0
    ollama_num_ctx: int = Field(
        default=16384,
        validation_alias=AliasChoices("CLASSIFICATION_CONTEXT_WINDOW", "OLLAMA_NUM_CTX"),
    )
    ollama_embed_num_ctx: int = Field(
        default=8192,
        validation_alias=AliasChoices("EMBEDDING_CONTEXT_WINDOW", "OLLAMA_EMBED_NUM_CTX"),
    )
    ollama_ocr_num_ctx: int = Field(
        default=12288,
        validation_alias=AliasChoices("OCR_CONTEXT_WINDOW", "OLLAMA_OCR_NUM_CTX"),
    )
    ollama_model_swap_delay: float = Field(
        default=8.0,
        validation_alias=AliasChoices("OLLAMA_MODEL_SWAP_DELAY", "OLLAMA_MODEL_SWAP_DELAY_SECONDS"),
    )  # seconds to wait after unloading a model

    # --- OCR ---
    ocr_mode: str = "off"  # off | text | vision_light | vision_full
    ocr_requested_tag_id: int = 0  # 0 = no tag filter; otherwise Paperless tag ID required for OCR
    ocr_vision_model: str = "qwen3-vl:4b"
    ocr_vision_max_pages: int = 3
    ocr_vision_dpi: int = 150

    # --- Event-driven pipeline ---
    database_url: str = "postgresql+psycopg://archibot:archibot@postgres:5432/archibot"
    paperless_webhook_secret: str = ""

    # --- Worker ---
    poll_interval_seconds: int = 600
    context_max_docs: int = 5
    classification_max_tags: int = 4
    context_max_distance: float = 0.5  # 0 = no threshold/unlimited; 0.5 = default relevance cutoff
    hybrid_search_weight: float = 0.7  # 0.0 = FTS only, 1.0 = vector only, 0.7 = default blend
    max_doc_chars: int = 24000
    embed_max_chars: int = Field(
        default=6000,
        validation_alias=AliasChoices("EMBEDDING_MAX_CHARS", "EMBED_MAX_CHARS"),
    )
    embedding_document_timeout_seconds: int = Field(
        default=180,
        validation_alias=AliasChoices("EMBEDDING_DOCUMENT_TIMEOUT_SECONDS"),
    )
    # Retained only to safely absorb legacy environment/runtime exports. ADR-0018
    # requires the effective value to remain zero until separately approved safe automation exists.
    auto_commit_confidence: int = 0

    # --- LLM-as-Judge verification (optional second pass) ---
    enable_judge_verification: bool = False
    judge_confidence_threshold: int = 101  # 101 = judge all results (confidence is 0-100)
    ollama_judge_model: str = Field(
        default="qwen3:4b",
        validation_alias=AliasChoices("JUDGE_MODEL", "OLLAMA_JUDGE_MODEL"),
    )  # empty = reuse ollama_model (no extra GPU swap)

    # --- GUI ---
    gui_port: int = 8088
    gui_base_url: str = ""  # e.g. "https://classifier.local:8088" for external links
    gui_date_format: str = "%d.%m.%Y"
    app_timezone: str = "Europe/Vienna"
    cors_allowed_origins: str = ""  # comma-separated list, empty = disabled

    # --- Webhook ---
    webhook_secret: str = ""  # if set, POST /webhook/paperless requires this token
    webhook_log_raw_body: bool = False

    # --- MCP ---
    mcp_transport: str = "stdio"  # stdio | sse | streamable-http
    mcp_port: int = 3001
    mcp_host: str = "0.0.0.0"
    mcp_enable_write: bool = False  # write tools only registered when True
    mcp_api_key: str = ""  # legacy static key; empty = no legacy auth
    mcp_laravel_auth_enabled: bool = False
    mcp_laravel_path: str = "laravel"
    mcp_laravel_php_binary: str = "php"
    mcp_classify_rate_limit: int = 10  # max classifications per hour, 0 = unlimited

    # --- State ---
    data_dir: str = "/data"
    log_level: str = "INFO"

    @field_validator("database_url", mode="before")
    @classmethod
    def _require_postgresql_database_url(cls, value: Any) -> str:
        if not isinstance(value, str):
            raise ValueError("DATABASE_URL must be a PostgreSQL URL")
        return require_postgresql_database_url(value)

    @field_validator("auto_commit_confidence", mode="before")
    @classmethod
    def _disable_confidence_auto_commit(cls, _value: Any) -> int:
        """Ignore every legacy confidence threshold under ADR-0018."""
        return 0

    @field_validator("*", mode="before")
    @classmethod
    def _empty_non_string_env_uses_default(cls, value: Any, info: ValidationInfo) -> Any:
        """Treat empty env values for typed settings as unset.

        Docker Compose/.env files commonly contain placeholders such as
        ``PAPERLESS_INBOX_TAG_ID=``. Pydantic cannot parse an empty string as
        an int/float/bool, but for these optional configuration placeholders we
        want the declared default (usually 0/False/None) instead of failing at
        import time.
        """
        if value != "":
            return value

        field = cls.model_fields.get(info.field_name or "")
        if field is None or isinstance(field.default, str):
            return value
        if field.default is None or isinstance(field.default, (bool, int, float)):
            return field.default
        return value

    @property
    def prompts_dir(self) -> Path:
        # Source-relative (works in development)
        p = Path(__file__).parent.parent / "prompts"
        if p.is_dir():
            return p
        # Installed package: fall back to working directory (Docker WORKDIR)
        return Path.cwd() / "prompts"

    @property
    def cors_allowed_origins_list(self) -> list[str]:
        return [origin.strip() for origin in self.cors_allowed_origins.split(",") if origin.strip()]

    @property
    def ollama_embed_dim_resolved(self) -> int:
        """Expected embedding vector dimension.

        `ollama_embed_dim=0` enables auto mode for known model defaults.
        """
        if self.ollama_embed_dim > 0:
            return self.ollama_embed_dim

        model = (self.ollama_embed_model or "").lower()
        if "qwen3-embedding" in model and "4b" in model:
            return 2560
        if "qwen3-embedding" in model and "0.6b" in model:
            return 1024

        # Safe fallback for unknown models in auto mode.
        return 1024


_CONFIG_ENV_ALIASES = {
    "classification_model": "ollama_model",
    "archibot_embedding_model": "ollama_embed_model",
    "embedding_model": "ollama_embed_model",
    "embedding_dimension": "ollama_embed_dim",
    "ocr_text_model": "ollama_ocr_model",
    "classification_context_window": "ollama_num_ctx",
    "embedding_context_window": "ollama_embed_num_ctx",
    "ocr_context_window": "ollama_ocr_num_ctx",
    "judge_model": "ollama_judge_model",
    "openai_base_url": "ollama_url",
}


_SENSITIVE_STRING_SETTINGS = {
    "mcp_api_key",
    "openai_api_key",
    "paperless_token",
    "paperless_webhook_secret",
    "webhook_secret",
}


# Singleton
settings = Settings()  # type: ignore[call-arg]


def _apply_config_env_overrides() -> None:
    """Apply config.env overrides with highest priority.

    Docker-compose injects .env values as OS environment variables, which
    pydantic-settings prioritises over dotenv files.  This means changes
    saved via the Settings UI (written to config.env) are lost on restart.

    Fix: read config.env *after* the singleton is created and patch the
    values in, so they effectively have the highest priority.
    """
    config_path = Path(settings.data_dir) / "config.env"
    if not config_path.is_file():
        return

    for line in config_path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, _, raw = stripped.partition("=")
        field_name = key.strip().lower()
        field_name = _CONFIG_ENV_ALIASES.get(field_name, field_name)
        raw = raw.strip()

        if field_name == "auto_commit_confidence":
            object.__setattr__(settings, field_name, 0)
            continue

        if field_name not in Settings.model_fields:
            continue

        default = Settings.model_fields[field_name].default
        if (
            raw == ""
            and field_name in _SENSITIVE_STRING_SETTINGS
            and isinstance(default, str)
            and getattr(settings, field_name, "")
        ):
            continue

        try:
            if field_name == "database_url":
                coerced = require_postgresql_database_url(raw)
            elif isinstance(default, bool):
                coerced: Any = raw.lower() in ("true", "1", "yes")
            elif isinstance(default, int):
                coerced = int(raw)
            elif isinstance(default, float):
                coerced = float(raw)
            elif default is None:
                # Optional field (e.g. int | None)
                coerced = None if not raw or raw.lower() == "none" else int(raw)
            else:
                coerced = raw
        except (ValueError, TypeError):
            if field_name == "database_url":
                raise ValueError("DATABASE_URL in config.env must use PostgreSQL") from None
            continue

        object.__setattr__(settings, field_name, coerced)


_apply_config_env_overrides()


def assert_product_database_config() -> None:
    """Fail product entry points closed unless their database is PostgreSQL."""
    require_postgresql_database_url(settings.database_url)


# ---------------------------------------------------------------------------
# Field metadata for UI rendering
# ---------------------------------------------------------------------------
class _FieldMeta(dict):
    """Typed dict for field metadata — just a plain dict with documentation."""


def _fm(
    category: str,
    label: str,
    input_type: str = "text",
    *,
    required: bool = False,
    restart: str | None = None,
    help: str = "",
    sensitive: bool = False,
    read_only: bool = False,
    min: float | None = None,
    max: float | None = None,
    step: float | None = None,
    options: list[str] | None = None,
) -> dict[str, Any]:
    meta: dict[str, Any] = {
        "category": category,
        "label": label,
        "input_type": input_type,
        "required": required,
        "restart": restart,
        "help": help,
        "sensitive": sensitive,
        "read_only": read_only,
    }
    if min is not None:
        meta["min"] = min
    if max is not None:
        meta["max"] = max
    if step is not None:
        meta["step"] = step
    if options is not None:
        meta["options"] = options
    return meta


FIELD_META: dict[str, dict[str, Any]] = {
    # --- Paperless ---
    "paperless_url": _fm(
        "Paperless",
        "Paperless URL",
        "url",
        required=True,
        restart="component",
        help="Base URL of your Paperless-NGX instance",
    ),
    "paperless_inbox_tag_id": _fm(
        "Paperless",
        "Inbox Tag ID",
        "tag_select",
        required=True,
        restart="component",
        help="Tag ID used as inbox (e.g. 'Posteingang')",
    ),
    "paperless_processed_tag_id": _fm(
        "Paperless",
        "Processed Tag ID",
        "tag_select",
        help="Optional tag ID added after commit (e.g. 'Verarbeitet')",
    ),
    "keep_inbox_tag": _fm(
        "Paperless", "Keep Inbox Tag", "bool", help="Keep the inbox tag on documents after commit"
    ),
    # --- AI provider (shared) ---
    "llm_provider": _fm(
        "AI Provider",
        "Provider",
        "select",
        restart="component",
        help="ollama = Ollama-compatible API; openai_compatible = OpenAI-compatible /v1 API",
        options=["ollama", "openai_compatible"],
    ),
    "ollama_url": _fm(
        "AI Provider",
        "Base URL",
        "url",
        restart="component",
        help="Ollama base URL for native mode, or OpenAI-compatible base URL including /v1 (for example http://localhost:11434/v1).",
    ),
    "openai_api_key": _fm(
        "AI Provider",
        "OpenAI-compatible API Key",
        "password",
        restart="component",
        help="Optional bearer token for OpenAI-compatible local providers. Leave empty when the endpoint does not require authentication.",
        sensitive=True,
    ),
    "ollama_timeout_seconds": _fm(
        "AI Provider",
        "Timeout (seconds)",
        "number",
        restart="component",
        help="HTTP timeout for AI provider requests",
    ),
    "ollama_model_swap_delay": _fm(
        "AI Provider",
        "Ollama Model Swap Delay (seconds)",
        "number",
        help="Seconds to wait after unloading a model before loading the next one. "
        "Only used by Ollama-compatible providers that support model unloading. "
        "Set to 0 to disable.",
    ),
    # --- Phase 1: OCR ---
    "ocr_requested_tag_id": _fm(
        "Phase 1: OCR",
        "Tag ID to improve OCR",
        "tag_select",
        help="Only run OCR for documents with this Paperless tag. Empty disables the filter.",
    ),
    "ocr_mode": _fm(
        "Phase 1: OCR",
        "OCR Mode",
        "ocr_mode_select",
        help="off | text | vision_light | vision_full",
    ),
    "ollama_ocr_model": _fm(
        "Phase 1: OCR",
        "OCR Text Model",
        "model_select",
        restart="component",
        help="Model for text-only OCR correction (ocr_mode=text). 4b is a good 6GB preset.",
    ),
    "ocr_vision_model": _fm(
        "Phase 1: OCR",
        "Vision Model",
        "model_select",
        restart="component",
        help="Ollama model for vision OCR (empty = use Classification Model)",
    ),
    "ocr_vision_max_pages": _fm(
        "Phase 1: OCR",
        "Vision Max Pages",
        "number",
        help="Max document pages to process with vision model",
    ),
    "ocr_vision_dpi": _fm(
        "Phase 1: OCR",
        "Vision DPI",
        "number",
        help="Render resolution for PDF pages (pixels per inch)",
    ),
    "ollama_ocr_num_ctx": _fm(
        "Phase 1: OCR",
        "OCR Context Window (tokens)",
        "number",
        help="num_ctx for OCR models. Vision OCR needs more context (~1536 tokens/page image). Default: 12288.",
    ),
    # --- Phase 2: Embedding ---
    "ollama_embed_model": _fm(
        "Phase 2: Embedding",
        "Embedding Model",
        "model_select",
        restart="component",
        help="Ollama model for embeddings (e.g. qwen3-embedding:4b)",
    ),
    "ollama_embed_dim": _fm(
        "Phase 2: Embedding",
        "Embedding Dimension",
        "number",
        restart="app",
        help="Expected embedding vector dimension. 0 = auto (qwen3-embedding:0.6b -> 1024, qwen3-embedding:4b -> 2560).",
    ),
    "ollama_embed_num_ctx": _fm(
        "Phase 2: Embedding",
        "Context Window (tokens)",
        "number",
        help="num_ctx for the embedding model (Ollama may clamp to model's n_ctx_train — check Ollama logs)",
    ),
    "embed_max_chars": _fm(
        "Phase 2: Embedding",
        "Max Document Chars",
        "number",
        help="Max characters of document text used for embedding (similarity search)",
    ),
    "ollama_embed_retries": _fm(
        "Phase 2: Embedding",
        "Retries",
        "number",
        help="Max retries for embedding requests (context-length + transient errors)",
    ),
    "ollama_embed_retry_base_delay": _fm(
        "Phase 2: Embedding",
        "Retry Base Delay (seconds)",
        "number",
        help="Base delay for exponential backoff on transient errors",
    ),
    # --- Phase 3: Klassifikation ---
    "ollama_model": _fm(
        "Phase 3: Klassifikation",
        "Classification Model",
        "model_select",
        restart="component",
        help="Ollama model for classification (e.g. gemma4:e4b)",
    ),
    "ollama_num_ctx": _fm(
        "Phase 3: Klassifikation",
        "Context Window (tokens)",
        "number",
        help="num_ctx for the classification model",
    ),
    "ollama_chat_retries": _fm(
        "Phase 3: Klassifikation",
        "Retries",
        "number",
        help="Max retries for chat/classification/OCR requests on transient errors",
    ),
    "ollama_chat_retry_base_delay": _fm(
        "Phase 3: Klassifikation",
        "Retry Base Delay (seconds)",
        "number",
        help="Base delay for exponential backoff on transient chat errors",
    ),
    "max_doc_chars": _fm(
        "Phase 3: Klassifikation",
        "Max Document Chars",
        "number",
        help="Max characters of document text sent to the classification LLM",
    ),
    "context_max_docs": _fm(
        "Phase 3: Klassifikation",
        "Context Max Docs",
        "number",
        help="Max similar documents used as few-shot context",
    ),
    "classification_max_tags": _fm(
        "Phase 3: Klassifikation",
        "Max Tags",
        "number",
        help="Maximum number of tags the classifier may propose per document",
    ),
    "context_max_distance": _fm(
        "Phase 3: Klassifikation",
        "Context Max Distance",
        "slider",
        help=(
            "EN: Maximum distance for related context matches. 0 = unlimited/no distance threshold; "
            "lower values are stricter, higher values include broader context. Context Max Docs still limits "
            "the final amount of context. DE: Maximale Distanz für verwandte Kontext-Treffer. 0 = unbegrenzt/"
            "kein Distanz-Schwellwert; kleinere Werte sind strenger, größere Werte erlauben breiteren Kontext. "
            "Context Max Docs begrenzt weiterhin die endgültige Kontextmenge."
        ),
        min=0.0,
        max=1.0,
        step=0.1,
    ),
    "hybrid_search_weight": _fm(
        "Phase 2: Embedding",
        "Hybrid Search Weight",
        "number",
        help="Blend ratio for hybrid search: 0.0 = keyword only, 1.0 = vector only, 0.7 = default",
    ),
    "auto_commit_confidence": _fm(
        "Phase 3: Klassifikation",
        "Confidence Auto-Commit (suspended)",
        "number",
        help="Read-only effective value 0. ADR-0018 temporarily suspends model-confidence writes; every classification requires manual review.",
        read_only=True,
        min=0,
        max=0,
    ),
    "enable_judge_verification": _fm(
        "Phase 3: Klassifikation",
        "Enable Judge Verification",
        "bool",
        help="Run a second LLM pass that verifies and optionally corrects classifications. "
        "Use Judge Confidence Threshold=101 to verify every result.",
    ),
    "judge_confidence_threshold": _fm(
        "Phase 3: Klassifikation",
        "Judge Confidence Threshold",
        "number",
        help="Skip judge pass when initial confidence is >= this value. 101 = judge every result. "
        "Lower values verify fewer high-confidence results and reduce cost.",
    ),
    "ollama_judge_model": _fm(
        "Phase 3: Klassifikation",
        "Judge Model",
        "model_select",
        restart="component",
        help="Ollama model used for judge pass. Recommended with gemma4:e4b classifier: qwen3:4b. Empty = reuse Classification Model (no GPU swap).",
    ),
    # --- Worker ---
    "poll_interval_seconds": _fm(
        "Worker",
        "Poll Interval (seconds)",
        "number",
        restart="component",
        help="Seconds between inbox polls (0 = disabled)",
    ),
    # --- GUI ---
    "gui_port": _fm("GUI", "Port", "number", restart="app", help="Web UI port (requires restart)"),
    "gui_base_url": _fm(
        "GUI",
        "External Base URL",
        "url",
        help="External URL for links generated outside the web UI (e.g. https://classifier.local:8088)",
    ),
    "gui_date_format": _fm(
        "GUI",
        "Date Format",
        help="Python strftime format for displayed dates in the GUI and Python CLI (default: %d.%m.%Y)",
    ),
    "app_timezone": _fm(
        "GUI",
        "Timezone",
        restart="app",
        help="IANA timezone for GUI and Python CLI date/time display (default: Europe/Vienna)",
    ),
    "cors_allowed_origins": _fm(
        "GUI",
        "CORS Allowed Origins",
        help="Comma-separated allowlist of origins for cross-origin browser access. Empty = disabled.",
    ),
    # --- Webhook ---
    "webhook_secret": _fm(
        "Webhook",
        "Webhook Secret",
        "password",
        help="Shared secret for POST /webhook/paperless",
        sensitive=True,
    ),
    "webhook_log_raw_body": _fm(
        "Webhook",
        "Log Raw Webhook Body",
        "bool",
        help="Debug only. When enabled, logs a truncated/redacted webhook payload preview. Default is false.",
    ),
    # --- MCP ---
    "mcp_transport": _fm("MCP", "Transport", restart="app", help="stdio | sse | streamable-http"),
    "mcp_port": _fm("MCP", "Port", "number", restart="app", help="MCP server port (SSE/HTTP)"),
    "mcp_host": _fm("MCP", "Host", restart="app", help="MCP server bind address"),
    "mcp_enable_write": _fm(
        "MCP", "Enable Write Tools", "bool", restart="app", help="Allow write operations via MCP"
    ),
    "mcp_api_key": _fm(
        "MCP",
        "Legacy API Key",
        "password",
        restart="app",
        help="Legacy static MCP auth key. Prefer Laravel-managed per-user MCP tokens on the Laravel migration branch.",
        sensitive=True,
    ),
    "mcp_laravel_auth_enabled": _fm(
        "MCP",
        "Use Laravel MCP Tokens",
        "bool",
        restart="app",
        help="Validate MCP tool tokens through Laravel's archibot:mcp-token-verify command.",
    ),
    "mcp_laravel_path": _fm(
        "MCP",
        "Laravel App Path",
        restart="app",
        help="Path to the Laravel app used for MCP token verification.",
    ),
    "mcp_laravel_php_binary": _fm(
        "MCP",
        "Laravel PHP Binary",
        restart="app",
        help="PHP executable used to run Laravel MCP token verification.",
    ),
    "mcp_classify_rate_limit": _fm(
        "MCP",
        "Classify Rate Limit",
        "number",
        restart="app",
        help="Max AI classifications per hour (0 = unlimited)",
    ),
    # --- System ---
    "data_dir": _fm(
        "System",
        "Data Directory",
        restart="app",
        help="Persistent data directory (DB, config, prompts)",
    ),
    "log_level": _fm("System", "Log Level", restart="app", help="DEBUG, INFO, WARNING, ERROR"),
}
