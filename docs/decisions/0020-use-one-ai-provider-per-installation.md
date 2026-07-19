# ADR-0020: Use One AI Provider per Installation

## Status

Accepted.

## Context

ArchiBot previously supported a default AI provider plus JSON-defined named provider profiles and separate provider-profile IDs for classification, embeddings, OCR and judge requests. This exposed transport routing, profile identifiers and raw JSON in setup-facing administration. Model discovery, role assignment and validation consequently required several disconnected controls.

The product still needs different models for classification, embeddings, OCR text, OCR vision and judge. That requirement does not require ArchiBot itself to route each role to a different endpoint. Operators that need multiple upstream AI backends can place one compatible gateway, such as LiteLLM, behind the single ArchiBot endpoint and select its model aliases per role.

Provider endpoints are a trust boundary because enabled roles may send document text or OCR content to them. Silently retaining or falling back among role-specific endpoints would make data egress difficult to understand.

## Decision

Each ArchiBot installation uses exactly one AI provider endpoint, protocol type and optional API credential for classification, embeddings, OCR and judge requests. Each role keeps its own configurable model ID. Classification and embeddings remain the core model roles; OCR text, OCR vision and judge models are used when their corresponding features are enabled.

The authenticated admin settings surface owns provider discovery and role-specific model validation. Provider endpoint configuration remains unavailable before the Paperless administrator has completed the public bootstrap, preserving the existing SSRF boundary. The post-bootstrap AI section presents the provider connection and all role model fields together.

`AI_PROVIDER_PROFILES`, `CLASSIFICATION_PROVIDER`, `EMBEDDING_PROVIDER`, `OCR_PROVIDER` and `JUDGE_PROVIDER` are retired. Python does not parse or route them. Laravel removes stale copies from the managed runtime export. Existing installations must promote their intended common endpoint to `LLM_PROVIDER`, `OLLAMA_URL`/`OPENAI_BASE_URL` and, when needed, `OPENAI_API_KEY` before relying on the upgraded runtime.

A gateway that routes model aliases to multiple upstream services still counts as the installation's one provider. Its routing and data-egress policy are outside ArchiBot and must be governed as part of that provider trust boundary.

## Consequences

- Setup and administration no longer expose provider-profile JSON or per-role provider IDs.
- Python uses one HTTP client and one authentication context for all AI roles.
- Model discovery runs once against the installation provider; model IDs remain independently configurable and validatable per role.
- Direct combinations such as local embeddings plus a separate cloud judge endpoint are no longer configured in ArchiBot. Operators needing that topology must use one gateway endpoint.
- Upgrades from a multi-provider configuration require an explicit choice of the common provider; there is no silent role-based fallback.

## References

- [Configuration](../user/configuration.md)
- [Installation](../user/installation.md)
- [Trust-boundary register](../governance/trust-boundaries.md)
