"""Ollama client: chat (with JSON mode) and embeddings."""

from __future__ import annotations

import asyncio
import json
import random
import re
from typing import Any

import httpx
import structlog

from app.config import settings
from app.db import EMBED_DIM

log = structlog.get_logger(__name__)

_MD_JSON_RE = re.compile(r"^\s*```(?:json)?\s*\n?(.*?)\n?\s*```\s*$", re.DOTALL)


def _strip_markdown_fences(text: str) -> str:
    """Extract raw JSON from Ollama responses.

    Handles three cases:
    1. JSON wrapped in markdown fences (``` or ```json).
    2. Extra leading/trailing non-JSON markers such as "---".
    3. Plain JSON without any fences.

    The function first removes any markdown code fences, then locates the
    outermost ``{`` and ``}`` characters to slice out a well-formed JSON object.
    """
    # Strip markdown fences if they exist
    m = _MD_JSON_RE.search(text)
    cleaned = m.group(1) if m else text
    # Find the first opening brace and the last closing brace
    start = cleaned.find("{")
    end = cleaned.rfind("}")
    if start != -1 and end != -1 and end > start:
        cleaned = cleaned[start : end + 1]
    return cleaned.strip()


def _exc_to_str(exc: Exception) -> str:
    """Return a useful error string even when ``str(exc)`` is empty."""
    text = str(exc).strip()
    return text or exc.__class__.__name__


class OllamaClient:
    def __init__(self, base_url: str | None = None, model: str | None = None) -> None:
        self.provider = (settings.llm_provider or "ollama").strip().lower()
        self.base_url = (base_url or settings.ollama_url).rstrip("/")
        self.model = model or settings.ollama_model
        self.embed_model = settings.ollama_embed_model
        self.ocr_model = settings.ollama_ocr_model
        self._client = httpx.AsyncClient(
            base_url=self.base_url,
            timeout=httpx.Timeout(settings.ollama_timeout_seconds),
        )
        self._clients: dict[str, httpx.AsyncClient] = {}
        self.embed_retry_count: int = 0

    def _provider(self) -> str:
        return getattr(self, "provider", "ollama")

    def _provider_for_role(self, role: str) -> dict[str, str]:
        try:
            provider = dict(settings.ai_provider_for_role(role))
        except (AttributeError, TypeError, ValueError):
            provider = {
                "id": "default",
                "type": self._provider(),
                "base_url": getattr(self, "base_url", ""),
                "api_key": getattr(settings, "openai_api_key", ""),
            }
        if provider.get("id") == "default":
            provider["type"] = self._provider()
            provider["base_url"] = getattr(self, "base_url", settings.ollama_url)
            provider["api_key"] = settings.openai_api_key
        return provider

    def _provider_type(self, provider: dict[str, str] | None = None) -> str:
        if provider is None:
            return self._provider()
        return (provider.get("type") or "ollama").strip().lower()

    def _client_for_provider(self, provider: dict[str, str] | None = None) -> httpx.AsyncClient:
        if provider is None:
            return self._client
        current_base_url = getattr(self, "base_url", settings.ollama_url).rstrip("/")
        base_url = (provider.get("base_url") or current_base_url).rstrip("/")
        if base_url == current_base_url:
            return self._client
        if not hasattr(self, "_clients"):
            self._clients = {}
        if base_url not in self._clients:
            self._clients[base_url] = httpx.AsyncClient(
                base_url=base_url,
                timeout=httpx.Timeout(settings.ollama_timeout_seconds),
            )
        return self._clients[base_url]

    async def aclose(self) -> None:
        await self._client.aclose()
        for client in getattr(self, "_clients", {}).values():
            await client.aclose()

    # ---------------------------------------------------------------
    # Health
    # ---------------------------------------------------------------
    async def ping(self) -> bool:
        try:
            endpoint = "/models" if self._provider() == "openai_compatible" else "/api/tags"
            r = await self._client.get(endpoint, headers=self._auth_headers())
            return r.status_code == 200
        except Exception as exc:
            log.warning("ollama ping failed", error=str(exc))
            return False

    def _role_for_model(self, model: str) -> str:
        if model == self.embed_model:
            return "embedding"
        if model == getattr(self, "ocr_model", "") or model == settings.ocr_vision_model:
            return "ocr"
        if model == settings.ollama_judge_model:
            return "judge"
        return "classification"

    async def unload_model(self, model: str, *, swap: bool = False) -> None:
        """Unload a model from VRAM via keep_alive=0.

        When *swap* is True, wait ``ollama_model_swap_delay`` seconds after
        unloading so the GPU can fully free memory before the next model loads.
        Terminal cleanup calls should leave *swap* as False to avoid needless
        latency.
        """
        provider = self._provider_for_role(self._role_for_model(model))
        if self._provider_type(provider) == "openai_compatible":
            log.debug("model unload skipped for OpenAI-compatible provider", model=model)
            return

        try:
            await self._client_for_provider(provider).post(
                "/api/generate",
                json={"model": model, "keep_alive": 0},
            )
            log.info("model unloaded", model=model)
        except Exception as exc:
            log.warning("failed to unload model", model=model, error=str(exc))
        # Give the GPU time to fully free memory before loading the next model.
        # Without this delay, Ollama's GPU discovery may timeout and use stale
        # VRAM readings, leading to suboptimal GPU/CPU weight distribution.
        if swap:
            delay = settings.ollama_model_swap_delay
            if delay > 0:
                log.debug("waiting for GPU memory recovery", delay_s=delay)
                await asyncio.sleep(delay)

    def _auth_headers(self, provider: dict[str, str] | None = None) -> dict[str, str]:
        if provider is None:
            api_key = settings.openai_api_key
            provider_type = self._provider()
        else:
            api_key = provider.get("api_key", "")
            provider_type = self._provider_type(provider)
        if provider_type != "openai_compatible" or not api_key:
            return {}
        return {"Authorization": f"Bearer {api_key}"}

    async def list_models(self) -> list[str]:
        """Return model names available from the configured provider."""
        if self._provider() == "openai_compatible":
            r = await self._client.get("/models", headers=self._auth_headers())
            r.raise_for_status()
            data = r.json()
            raw_models = data.get("data", [])
            names = [str(m.get("id", "")).strip() for m in raw_models if isinstance(m, dict)]
            return sorted({name for name in names if name})

        r = await self._client.get("/api/tags")
        r.raise_for_status()
        data = r.json()
        names = [str(m.get("name", "")).strip() for m in data.get("models", [])]
        return sorted({name for name in names if name})

    async def model_available(self, name: str) -> bool:
        try:
            tags = await self.list_models()
            return any(t == name or t.startswith(name + ":") for t in tags)
        except Exception:
            return False

    @staticmethod
    def _parse_chat_json_content(content: str, *, vision: bool = False) -> dict[str, Any]:
        if not content:
            raise ValueError("Ollama returned empty content")

        parse_err: json.JSONDecodeError | None = None
        try:
            return json.loads(content)
        except json.JSONDecodeError as exc:
            parse_err = exc

        # Some models wrap JSON in markdown fences despite format="json"
        stripped = _strip_markdown_fences(content)
        if stripped != content:
            try:
                return json.loads(stripped)
            except json.JSONDecodeError as exc:
                parse_err = exc

        msg = "ollama vision returned invalid json" if vision else "ollama returned invalid json"
        log.error(
            msg,
            content=content[:500],
            content_len=len(content),
            json_error=_exc_to_str(parse_err or ValueError("json decode failed")),
        )
        source = "Ollama vision" if vision else "Ollama"
        raise ValueError(f"Invalid JSON from {source}: {content[:200]}") from None

    @staticmethod
    def _make_strict_json_retry_payload(payload: dict[str, Any]) -> dict[str, Any]:
        """Harden a chat payload for JSON-recovery retries.

        Used after malformed JSON responses to increase deterministic output.
        """
        retry_payload = dict(payload)

        options = dict(retry_payload.get("options") or {})
        options["temperature"] = 0.0
        retry_payload["options"] = options

        messages = list(retry_payload.get("messages") or [])
        reminder = (
            "Your previous response was not valid JSON. "
            "Return ONLY one valid JSON object. "
            "No markdown fences, no prose, no prefix/suffix text."
        )
        messages.append({"role": "user", "content": reminder})
        retry_payload["messages"] = messages
        return retry_payload

    async def _chat_json_with_retries(
        self,
        payload: dict[str, Any],
        *,
        vision: bool = False,
    ) -> dict[str, Any]:
        return await self._chat_json_with_retries_for_provider(
            payload, provider=None, vision=vision
        )

    async def _chat_json_with_retries_for_provider(
        self,
        payload: dict[str, Any],
        *,
        provider: dict[str, str] | None = None,
        vision: bool = False,
    ) -> dict[str, Any]:
        # Keep this bounded to avoid long stalls on OCR/classification workloads.
        retries_raw = getattr(settings, "ollama_chat_retries", 1)
        base_delay_raw = getattr(settings, "ollama_chat_retry_base_delay", 1.0)
        max_retries = retries_raw if isinstance(retries_raw, int) else 1
        base_delay = float(base_delay_raw) if isinstance(base_delay_raw, int | float) else 1.0
        last_exc: Exception | None = None
        current_payload = payload

        for attempt in range(1 + max_retries):
            try:
                r = await self._client_for_provider(provider).post(
                    "/api/chat", json=current_payload
                )
                r.raise_for_status()
                data = r.json()
                content = data.get("message", {}).get("content", "")
                return self._parse_chat_json_content(content, vision=vision)
            except Exception as exc:
                last_exc = exc
                is_retryable = self._is_retryable(exc) or isinstance(exc, ValueError)
                if attempt < max_retries and is_retryable:
                    delay = 0.0
                    if isinstance(exc, ValueError):
                        current_payload = self._make_strict_json_retry_payload(current_payload)
                    else:
                        delay = self._backoff_delay(base_delay, attempt)
                    log.warning(
                        "ollama chat request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        error=_exc_to_str(exc),
                    )
                    if delay > 0:
                        await asyncio.sleep(delay)
                    continue
                raise

        raise last_exc  # type: ignore[misc]

    async def _openai_chat_completion_with_retries(
        self,
        payload: dict[str, Any],
        *,
        retry_count: int,
        base_delay: float,
        log_label: str,
        provider: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        """POST /chat/completions with retry handling for OpenAI-compatible APIs."""
        for attempt in range(1 + retry_count):
            try:
                r = await self._client_for_provider(provider).post(
                    "/chat/completions",
                    json=payload,
                    headers=self._auth_headers(provider),
                )
                r.raise_for_status()
                return r.json()
            except Exception as exc:
                if attempt < retry_count and self._is_retryable(exc):
                    delay = self._backoff_delay(base_delay, attempt)
                    log.warning(
                        f"{log_label} request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        error=_exc_to_str(exc),
                    )
                    await asyncio.sleep(delay)
                    continue
                raise

    async def _openai_chat_json_with_retries(
        self,
        payload: dict[str, Any],
        *,
        provider: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        retries_raw = getattr(settings, "ollama_chat_retries", 1)
        base_delay_raw = getattr(settings, "ollama_chat_retry_base_delay", 1.0)
        max_retries = retries_raw if isinstance(retries_raw, int) else 1
        base_delay = float(base_delay_raw) if isinstance(base_delay_raw, int | float) else 1.0
        current_payload = payload

        for attempt in range(1 + max_retries):
            try:
                data = await self._openai_chat_completion_with_retries(
                    current_payload,
                    retry_count=0,
                    base_delay=base_delay,
                    log_label="openai-compatible chat json",
                    provider=provider,
                )
                content = data.get("choices", [{}])[0].get("message", {}).get("content", "")
                return self._parse_chat_json_content(content)
            except Exception as exc:
                response_format_rejected = (
                    isinstance(exc, httpx.HTTPStatusError)
                    and exc.response.status_code == 400
                    and "response_format" in current_payload
                )
                is_retryable = self._is_retryable(exc) or isinstance(exc, ValueError)
                if attempt < max_retries and (is_retryable or response_format_rejected):
                    delay = 0.0
                    if response_format_rejected:
                        current_payload = dict(current_payload)
                        current_payload.pop("response_format", None)
                    elif isinstance(exc, ValueError):
                        current_payload = self._make_strict_openai_json_retry_payload(
                            current_payload
                        )
                    else:
                        delay = self._backoff_delay(base_delay, attempt)
                    log.warning(
                        "openai-compatible chat request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        error=_exc_to_str(exc),
                    )
                    if delay > 0:
                        await asyncio.sleep(delay)
                    continue
                raise

        raise RuntimeError("openai-compatible chat json retry loop exhausted")

    @staticmethod
    def _make_strict_openai_json_retry_payload(payload: dict[str, Any]) -> dict[str, Any]:
        retry_payload = dict(payload)
        retry_payload["temperature"] = 0.0
        messages = list(retry_payload.get("messages") or [])
        messages.append(
            {
                "role": "user",
                "content": "Your previous response was not valid JSON. Return ONLY one valid JSON object. No markdown fences, no prose.",
            }
        )
        retry_payload["messages"] = messages
        return retry_payload

    # ---------------------------------------------------------------
    # Chat (JSON mode)
    # ---------------------------------------------------------------
    async def chat_json(
        self,
        system: str,
        user: str,
        *,
        model: str | None = None,
        temperature: float = 0.1,
        num_ctx: int | None = None,
        role: str = "classification",
    ) -> dict[str, Any]:
        """Call the configured chat provider and parse a JSON response."""
        provider = self._provider_for_role(role)
        if self._provider_type(provider) == "openai_compatible":
            payload = {
                "model": model or self.model,
                "stream": False,
                "temperature": temperature,
                "response_format": {"type": "json_object"},
                "messages": [
                    {"role": "system", "content": system},
                    {"role": "user", "content": user},
                ],
            }
            return await self._openai_chat_json_with_retries(payload, provider=provider)

        payload = {
            "model": model or self.model,
            "format": "json",
            "stream": False,
            "options": {
                "temperature": temperature,
                "num_ctx": num_ctx if num_ctx is not None else settings.ollama_num_ctx,
            },
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }
        return await self._chat_json_with_retries_for_provider(
            payload, provider=provider, vision=False
        )

    # ---------------------------------------------------------------
    # Chat with vision (JSON mode)
    # ---------------------------------------------------------------
    async def chat_vision_json(
        self,
        system: str,
        user: str,
        images: list[str],
        *,
        model: str | None = None,
        temperature: float = 0.1,
        num_ctx: int | None = None,
        role: str = "ocr",
    ) -> dict[str, Any]:
        """Call provider chat with images and parse a JSON response.

        *images* must be a list of base64-encoded image strings (no data URI prefix).
        """
        provider = self._provider_for_role(role)
        if self._provider_type(provider) == "openai_compatible":
            content: list[dict[str, Any]] = [{"type": "text", "text": user}]
            content.extend(
                {
                    "type": "image_url",
                    "image_url": {"url": f"data:image/png;base64,{image}"},
                }
                for image in images
            )
            payload = {
                "model": model or self.model,
                "stream": False,
                "temperature": temperature,
                "response_format": {"type": "json_object"},
                "messages": [
                    {"role": "system", "content": system},
                    {"role": "user", "content": content},
                ],
            }
            return await self._openai_chat_json_with_retries(payload, provider=provider)

        payload = {
            "model": model or self.model,
            "format": "json",
            "stream": False,
            "options": {
                "temperature": temperature,
                "num_ctx": num_ctx if num_ctx is not None else settings.ollama_num_ctx,
            },
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user, "images": images},
            ],
        }
        return await self._chat_json_with_retries_for_provider(
            payload, provider=provider, vision=True
        )

    # ---------------------------------------------------------------
    # Chat (plain text, for conversational RAG)
    # ---------------------------------------------------------------
    async def chat(
        self,
        messages: list[dict[str, str]],
        *,
        model: str | None = None,
        temperature: float = 0.3,
    ) -> str:
        """Call Ollama chat and return the plain-text response.

        Unlike ``chat_json()``, this does **not** set ``format="json"`` and
        returns the raw assistant message content.  Designed for conversational
        RAG where the response is natural language.

        *messages* is the full conversation: system, prior turns, and the
        current user message.
        """
        retries_raw = getattr(settings, "ollama_chat_retries", 1)
        base_delay_raw = getattr(settings, "ollama_chat_retry_base_delay", 1.0)
        retries = retries_raw if isinstance(retries_raw, int) else 1
        base_delay = float(base_delay_raw) if isinstance(base_delay_raw, int | float) else 1.0

        provider = self._provider_for_role("chat")
        if self._provider_type(provider) == "openai_compatible":
            payload = {
                "model": model or self.model,
                "stream": False,
                "temperature": temperature,
                "messages": messages,
            }
            data = await self._openai_chat_completion_with_retries(
                payload,
                retry_count=retries,
                base_delay=base_delay,
                log_label="openai-compatible plain chat",
                provider=provider,
            )
            content = data.get("choices", [{}])[0].get("message", {}).get("content", "")
            if not content:
                raise ValueError("OpenAI-compatible provider returned empty content")
            return content

        payload = {
            "model": model or self.model,
            "stream": False,
            "options": {"temperature": temperature, "num_ctx": settings.ollama_num_ctx},
            "messages": messages,
        }
        data = await self._post_chat_with_retry(
            payload,
            retry_count=retries,
            base_delay=base_delay,
            log_label="ollama plain chat",
            provider=provider,
        )
        content = data.get("message", {}).get("content", "")
        if not content:
            raise ValueError("Ollama returned empty content")
        return content

    # ---------------------------------------------------------------
    # Embeddings
    # ---------------------------------------------------------------
    @staticmethod
    def _is_context_length_error(response: httpx.Response) -> bool:
        """Check if a 500 response is caused by input exceeding the context length."""
        try:
            body = response.text
            return "context length" in body.lower()
        except Exception:
            return False

    @staticmethod
    def _http_error_detail(exc: httpx.HTTPStatusError, *, provider: dict[str, str]) -> str:
        body = exc.response.text.strip()
        if len(body) > 1000:
            body = body[:1000] + "...[truncated]"
        provider_id = provider.get("id") or "default"
        base_url = provider.get("base_url") or ""
        detail = (
            f"Embedding provider returned HTTP {exc.response.status_code}"
            f" provider={provider_id} base_url={base_url}"
        )
        if body:
            detail += f" body={body}"
        return detail

    @staticmethod
    def _is_retryable(exc: Exception) -> bool:
        if isinstance(exc, httpx.HTTPStatusError):
            code = exc.response.status_code
            return code == 429 or code >= 500
        return isinstance(
            exc,
            (
                httpx.ConnectError,
                httpx.ConnectTimeout,
                httpx.ReadTimeout,
                httpx.WriteTimeout,
                httpx.PoolTimeout,
                httpx.RemoteProtocolError,
                httpx.ReadError,
                httpx.WriteError,
                httpx.TimeoutException,
            ),
        )

    @staticmethod
    def _backoff_delay(base_delay: float, attempt: int) -> float:
        """Exponential backoff with jitter for retry attempt ``attempt``."""
        return base_delay * (2**attempt) + random.uniform(0, 0.5)

    async def _post_chat_with_retry(
        self,
        payload: dict[str, Any],
        *,
        retry_count: int,
        base_delay: float,
        log_label: str,
        provider: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        """POST /api/chat with retry handling for transient errors."""
        for attempt in range(1 + retry_count):
            try:
                r = await self._client_for_provider(provider).post("/api/chat", json=payload)
                r.raise_for_status()
                return r.json()
            except Exception as exc:
                if attempt < retry_count and self._is_retryable(exc):
                    delay = self._backoff_delay(base_delay, attempt)
                    log.warning(
                        f"{log_label} request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        error=_exc_to_str(exc),
                    )
                    await asyncio.sleep(delay)
                    continue
                raise

    @staticmethod
    def _parse_json_content(content: str, *, source: str) -> dict[str, Any]:
        """Parse JSON content, handling occasional markdown fence wrappers."""
        try:
            return json.loads(content)
        except json.JSONDecodeError:
            stripped = _strip_markdown_fences(content)
            if stripped != content:
                try:
                    return json.loads(stripped)
                except json.JSONDecodeError:
                    pass
            log.error(f"{source} returned invalid json", content=content[:500])
            raise ValueError(f"Invalid JSON from {source}: {content[:200]}") from None

    async def embed(self, text: str) -> list[float]:
        max_retries = settings.ollama_embed_retries
        base_delay = settings.ollama_embed_retry_base_delay
        prompt = text
        last_exc: Exception | None = None
        provider = self._provider_for_role("embedding")

        for attempt in range(1 + max_retries):
            if self._provider_type(provider) == "openai_compatible":
                payload = {"model": self.embed_model, "input": prompt}
            else:
                payload = {
                    "model": self.embed_model,
                    "prompt": prompt,
                    "options": {"num_ctx": settings.ollama_embed_num_ctx},
                }
            try:
                if self._provider_type(provider) == "openai_compatible":
                    r = await self._client_for_provider(provider).post(
                        "/embeddings",
                        json=payload,
                        headers=self._auth_headers(provider),
                    )
                    r.raise_for_status()
                    data = r.json()
                    items = data.get("data") or []
                    vec = (
                        items[0].get("embedding") if items and isinstance(items[0], dict) else None
                    )
                else:
                    r = await self._client_for_provider(provider).post(
                        "/api/embeddings", json=payload
                    )
                    r.raise_for_status()
                    data = r.json()
                    vec = data.get("embedding")
                if not vec:
                    source = (
                        "OpenAI-compatible provider"
                        if self._provider_type(provider) == "openai_compatible"
                        else "Ollama"
                    )
                    raise ValueError(f"{source} returned empty embedding")
                expected_dim = EMBED_DIM
                if len(vec) != expected_dim:
                    raise ValueError(
                        f"Unexpected embedding dimension: got {len(vec)}, expected {expected_dim}"
                    )
                return vec
            except httpx.HTTPStatusError as exc:
                last_exc = exc
                if attempt < max_retries and self._is_context_length_error(exc.response):
                    # Input too long — truncate by 50% and retry immediately
                    prompt = prompt[: int(len(prompt) * 0.50)]
                    self.embed_retry_count += 1
                    log.warning(
                        "embedding input exceeds context length, truncating"
                        " — consider lowering EMBED_MAX_CHARS"
                        f" (currently {settings.embed_max_chars})",
                        attempt=attempt + 1,
                        new_len=len(prompt),
                    )
                    continue
                if attempt < max_retries and self._is_retryable(exc):
                    delay = self._backoff_delay(base_delay, attempt)
                    self.embed_retry_count += 1
                    log.warning(
                        "embedding request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        status=exc.response.status_code,
                        body=exc.response.text[:500],
                    )
                    await asyncio.sleep(delay)
                    continue
                detail = self._http_error_detail(exc, provider=provider)
                log.warning("embedding provider request failed", error=detail)
                raise ValueError(detail) from exc
            except Exception as exc:
                last_exc = exc
                if attempt < max_retries and self._is_retryable(exc):
                    delay = self._backoff_delay(base_delay, attempt)
                    self.embed_retry_count += 1
                    log.warning(
                        "embedding request failed, retrying",
                        attempt=attempt + 1,
                        delay_s=round(delay, 2),
                        error=_exc_to_str(exc),
                    )
                    await asyncio.sleep(delay)
                    continue
                raise

        raise last_exc  # type: ignore[misc]
