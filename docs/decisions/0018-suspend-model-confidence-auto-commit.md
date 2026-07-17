# ADR-0018: Suspend Model-confidence Auto-commit

## Status

Accepted; containment implemented in hardening milestone 0.2. Supersedes the lightweight decision to preserve existing `auto_commit_confidence` behavior unchanged.

## Context

Paperless Document text is untrusted input. The classification model currently proposes metadata and reports the confidence value that can authorize automatic Paperless writes. Prompt hardening or a second LLM judge does not make model output an authorization decision, because both remain influenced by untrusted content and are not deterministic safety controls.

## Decision

Disable automatic commit based on model-reported confidence. Manual Review Suggestions remain the only write path until a separate implementation satisfies all re-enable criteria:

- deterministic, field-level eligibility rules;
- existing/approved entity restrictions and storage-path immutability;
- independently calibrated evidence that is not self-reported model confidence;
- explicit handling of prompt-injection and malformed-content cases;
- adversarial tests proving that document instructions cannot authorize writes;
- auditable configuration, rollback and operator-visible reasons;
- explicit product/security approval before re-enabling the feature.

An LLM judge may provide review evidence but is not, by itself, an authorization gate.

## Consequences

The containment implementation forces the effective auto-commit setting off in both Laravel and Python, presents the control as read-only, and preserves pending Review Suggestions for manual review. Existing configured thresholds cannot write to Paperless. Re-enabling automation is a later milestone, not part of containment.

## References

- [Security and architecture hardening plan](../implementation-plan-security-architecture-hardening.md)
