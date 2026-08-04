---
target: all of it
total_score: 22
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 5
timestamp: 2026-08-04T13-01-51Z
slug: laravel-resources-js
---
Method: dual-agent (A: worker/Assessment-A · B: worker/Assessment-B)

## Design Health Score

| # | Heuristic | Score | Key issue |
|---|---|---:|---|
| 1 | Visibility of System Status | 3 | Progress exists, but polling updates are not announced accessibly. |
| 2 | Match System / Real World | 2 | Terms such as actors, dedupe, drift, pgvector, and embedding gates expose implementation. |
| 3 | User Control and Freedom | 2 | Cancel/reset paths exist, but accepting a review has no undo or draft recovery. |
| 4 | Consistency and Standards | 3 | Shared components help, but raw controls and status styling diverge. |
| 5 | Error Prevention | 3 | Strong destructive confirmations; Setup still permits premature step changes. |
| 6 | Recognition Rather Than Recall | 2 | Comparison, editing, reasoning, actions, and preview are spatially separated. |
| 7 | Flexibility and Efficiency | 2 | Bulk review exists, but no select-all, shortcuts, saved filters, or global search. |
| 8 | Aesthetic and Minimalist Design | 2 | Long, equally weighted card stacks obscure priorities. |
| 9 | Error Recovery | 2 | Retry and dismiss exist, but errors rarely identify the next diagnostic action. |
| 10 | Help and Documentation | 1 | Help is sparse and empty states rarely teach the next step. |
| **Total** | | **22/40** | **Acceptable — safe foundation, significant workflow and hierarchy work needed.** |

## Design Specificity Verdict

**Product-specific content, category-interchangeable composition.** ArchiBot’s inbox trust boundary, review-before-write model, embedding readiness gate, OCR review, and durable pipeline history are distinctive. The visual system is not: repeated neutral bordered cards, muted pills, definition lists, and stacked admin sections could belong to almost any shadcn-derived operations product. Product character currently lives in copy rather than interaction design.

**Deterministic scan:** 1 warning across 186 supported files: `broken-image` at `laravel/resources/js/components/ui/avatar/AvatarImage.svelte:7`. This is likely a false positive because the component forwards `src` through `{...rest}`; it becomes a real defect only if a consumer omits or supplies an empty source.

**Visual overlays:** unavailable. Neither isolated assessment had browser automation, so no rendered-page inspection, injection, or reliable user-visible overlay exists. Contrast, clipping, density, and 200% zoom remain unverified.

## Overall Impression

ArchiBot is unusually serious about safety and operational truth, but the interface makes users absorb the architecture before it helps them act. The biggest opportunity is to make the core promise—confident, fast, reversible document review—the organizing principle, while moving deep telemetry into a secondary operations layer.

## What’s Working

1. **Safety copy is concrete.** Pinned Paperless origin, write-only secrets, local OCR, explicit write confirmation, and typed reset language explain real consequences (`Setup/Index.svelte`, `ocr/Show.svelte`, `admin/Maintenance.svelte`).
2. **Operational truth is comprehensive.** Progress, retries, linked artifacts, errors, timestamps, and audit evidence support failure recovery instead of hiding it.
3. **The component baseline is coherent.** Shared controls, responsive grids, focus states, pagination, sidebar behavior, and themes produce predictable mechanics.

## Cognitive Load and Emotional Journey

Cognitive load is **high: 7/8 checklist failures**. Grouping passes; single focus, chunking, visual hierarchy, one-thing-at-a-time, minimal choices, working-memory support, and progressive disclosure fail. Notable overload points include 17 sidebar destinations (8 under Processing), 9 review filters plus Apply/Reset, 7 editable review controls, and 6 peer maintenance commands plus recovery and danger actions.

The first-run experience is calm until Setup introduces credentials, webhook secrets, trust boundaries, and automation without a readiness summary. The dashboard reassures with “All clear” but presents an operations room before answering “What needs me now?” Review comparison feels responsible, yet separated rationale, editing, preview, and actions create doubt at the decision peak. Completion lacks undo, “next review,” queue progress, or confirmation that Paperless now matches the accepted state.

## Priority Issues

### 1. **P1 — IA mirrors subsystems, not operator jobs**
**Why it matters:** Seventeen destinations and overlapping Dashboard, Operations Log, Pipeline, Webhook, and Errors views force users to learn the backend model before acting.
**Fix:** Organize around **Review / Monitor / Configure**. Make the dashboard role-aware with “Needs attention / In progress / Healthy.” Nest webhooks, actors, and audit evidence beneath an operation; move rare diagnostics under Admin.
**Evidence:** `components/AppSidebar.svelte:63-159`, `pages/Dashboard.svelte:329-923`, `pages/operations-log/Index.svelte:169-216`.
**Suggested command:** `/impeccable shape`

### 2. **P1 — Review is a report, not a decision workspace**
**Why it matters:** Reviewers must visually diff cards, remember changes while editing, read detached rationale, act, and only then reach the preview. That weakens the safety promise.
**Fix:** Build a two-pane workspace: persistent document preview; field-level diff with changed values emphasized, unchanged fields collapsed, inline editing, and rationale adjacent to each change. Keep Accept/Reject sticky; add “Accept & next,” a rejection reason, and a reversible grace period.
**Evidence:** `pages/review/Show.svelte:218-484`.
**Suggested command:** `/impeccable layout`

### 3. **P1 — Status semantics collapse into neutral pills**
**Why it matters:** Queued, running, blocked, failed, accepted, and rejected often look alike, preventing fast urgency scanning.
**Fix:** Introduce one accessible status system using icon, label, and restrained semantic color plus a shared progress component. Never rely on color alone.
**Evidence:** `pages/pipeline-runs/Index.svelte:88-179`, `pages/webhooks/Index.svelte:76-165`; stronger precedent in `components/ActiveOperationsPanel.svelte:31-58`.
**Suggested command:** `/impeccable colorize`

### 4. **P1 — High-risk admin controls lack progressive disclosure**
**Why it matters:** Routine actions, six command variants, recovery, gate shutdown, manual processing, destructive reset, and audit history appear in one flow. Native confirms cannot explain scope or impact well.
**Fix:** Separate **Routine / Recovery / Danger zone**; feature the safe default, put variants under Advanced, show scope and duration, and use accessible impact dialogs with typed confirmation for gate closure/reset. Show the latest result beside its action.
**Evidence:** `pages/admin/Maintenance.svelte:145-344`, `pages/admin/Settings.svelte:321-1038`.
**Suggested command:** `/impeccable distill`

### 5. **P1 — Accessibility and keyboard semantics are incomplete**
**Why it matters:** Most pages begin at `h2`; filters rely on placeholders; progress lacks progress semantics; compact controls miss touch guidance; polling changes are silent. This makes core flows unreliable for keyboard, screen-reader, low-vision, and motor-impaired users.
**Fix:** Use a page `h1`, explicit labels and table captions, semantic progress, restrained live regions, 44px hit areas, skip links and focus restoration, select-all, and keyboard review actions. Give the icon-only search control an accessible name.
**Evidence:** `components/Heading.svelte:13-22`, `pages/review/Index.svelte:71-162`, `components/ActiveOperationsPanel.svelte:155-170`, `components/AppHeader.svelte:189-200`, `components/ui/button/Button.svelte:31-37`.
**Suggested command:** `/impeccable audit`

## Persona Red Flags

**Alex (Power User):** Seventeen destinations slow triage. Bulk review still requires item-by-item selection; there is no select-all, saved filters, keyboard command path, “accept and next,” or unified searchable timeline. Alex will work around the UI.

**Jordan (First-Timer):** The dashboard has no obvious first action. Actor, dedupe, gate, drift, judge, and durable recovery assume architecture knowledge. Empty states do not explain how data arrives, while Setup steps appear navigable before verification.

**Sam (Accessibility-Dependent):** Most pages start at `h2`; filters lack labels; progress and polling changes are not announced; compact controls increase motor demand; tables often lack captions. The icon-only Search control has no evident accessible name.

## Minor Observations

- “Operations Log” breaks sentence-case naming.
- Welcome presents Log in and Open dashboard as peers even though one likely redirects.
- The one-time MCP token lacks Copy and copied-confirmation controls.
- Audit Logs lacks the horizontal overflow treatment used by other tables.
- Empty states are safe but passive: no setup, refresh, or diagnostic action.

## Questions to Consider

1. Is ArchiBot’s primary promise **faster review** or **deep observability**, and which should own the default dashboard?
2. What minimum evidence must a cautious reviewer see before Accept, and what can remain collapsed?
3. Which maintenance command is the safe 80% default, and why are the other variants peers?
4. Does “complete” mean queued, committed to Paperless, or verified in Paperless?
