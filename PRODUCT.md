# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

The primary daily user is a self-hosting Paperless-NGX owner reviewing and organizing personal documents. Administrators also configure integrations, monitor processing, and recover failed work, but administration is secondary to the daily document-review experience.

## Product Purpose

ArchiBot helps a Paperless owner process incoming documents by suggesting metadata, presenting those suggestions for review, and applying approved changes through Paperless. Daily success means clearing the review queue quickly while retaining extra confidence at the higher-risk approval boundaries for new tags, correspondents, and document types.

## Positioning

ArchiBot combines AI-assisted document classification with an explicit review and permission model. It keeps untrusted inbox documents out of classification context, records durable processing and review state, and lets the owner verify suggested changes before Paperless is modified.

## Operating Context

ArchiBot is self-hosted alongside Paperless-NGX and used primarily on the web. Paperless webhooks trigger processing, periodic polling reconciles missed work, and the owner returns to ArchiBot to clear document reviews, approve proposed entities, inspect OCR suggestions, and diagnose exceptional failures. Routine document work should not require understanding the underlying queue, actor, webhook, or pipeline architecture.

## Capabilities and Constraints

- Paperless Documents may receive Review Suggestions for metadata.
- Inbox Documents are not trusted classification context.
- Tags, correspondents, and document types remain behind approval and whitelist controls.
- Paperless changes require authorized review; model or judge output cannot independently accept, queue, or write.
- Explicit force reprocessing remains available and creates a new Pipeline Run.
- OCR corrections remain local and are never written back to Paperless content.
- Operational controls are admin-only and durable PostgreSQL pipeline state remains authoritative.
- Webhooks are primary; polling is reconciliation through the same dedupe and locking path.
- The product remains self-hosted, Docker-first, and single-container.
- Existing behavior and safety constraints must remain intact, but navigation, information architecture, and workflow presentation may change.

## Brand Commitments

The product name is ArchiBot. Its voice should be direct, calm, factual, and reassuring without hiding risk. The redesigned interface should be calm and document-centric, with fewer simultaneous choices and progressive disclosure of technical operations.

## Evidence on Hand

The repository contains working Svelte/Laravel surfaces for onboarding, dashboard, inbox and review, entity approval, OCR review, pipeline operations, diagnostics, maintenance, settings, and authentication. Existing copy documents real safety boundaries and operational states. No customer claims, testimonials, benchmarks, or independent brand asset library are available and none should be fabricated.

## Product Principles

1. Put the current document and the next safe action ahead of infrastructure.
2. Optimize routine review for speed; slow down only at meaningful approval boundaries.
3. Reveal complexity progressively instead of presenting every available operation at once.
4. Make system state and consequences legible without requiring backend terminology.
5. Preserve manual control, permissions, durable evidence, and recoverability.

## Accessibility & Inclusion

The core review workflow must support keyboard and assistive-technology use, semantic headings and state announcements, non-color status cues, visible focus, and practical touch targets. Dense operational evidence must remain readable at narrow widths and browser zoom.
