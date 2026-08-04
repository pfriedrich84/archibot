---
name: "ArchiBot"
description: "A calm transfer register for reviewing and safely applying document metadata."
colors:
  background: "hsl(105 14% 96%)"
  foreground: "hsl(162 17% 11%)"
  card: "hsl(90 20% 99%)"
  card-foreground: "hsl(162 17% 11%)"
  popover: "hsl(90 20% 99%)"
  popover-foreground: "hsl(162 17% 11%)"
  primary: "hsl(169 32% 28%)"
  primary-foreground: "hsl(96 25% 97%)"
  secondary: "hsl(120 12% 90%)"
  secondary-foreground: "hsl(162 17% 14%)"
  muted: "hsl(110 11% 92%)"
  muted-foreground: "hsl(156 7% 36%)"
  accent: "hsl(150 18% 88%)"
  accent-foreground: "hsl(169 32% 22%)"
  destructive: "hsl(8 52% 43%)"
  destructive-foreground: "hsl(30 25% 98%)"
  border: "hsl(135 10% 82%)"
  input: "hsl(135 10% 76%)"
  ring: "hsl(169 32% 32%)"
  chart-1: "hsl(169 32% 36%)"
  chart-2: "hsl(22 55% 51%)"
  chart-3: "hsl(204 39% 38%)"
  chart-4: "hsl(44 55% 52%)"
  chart-5: "hsl(282 23% 46%)"
  sidebar-background: "hsl(120 13% 91%)"
  sidebar-foreground: "hsl(162 17% 17%)"
  sidebar-primary: "hsl(169 32% 25%)"
  sidebar-primary-foreground: "hsl(96 25% 97%)"
  sidebar-accent: "hsl(145 15% 85%)"
  sidebar-accent-foreground: "hsl(169 32% 20%)"
  sidebar-border: "hsl(135 10% 78%)"
  sidebar-ring: "hsl(169 32% 32%)"
  dark-background: "hsl(150 13% 8%)"
  dark-foreground: "hsl(105 14% 94%)"
  dark-card: "hsl(150 12% 10%)"
  dark-card-foreground: "hsl(105 14% 94%)"
  dark-popover: "hsl(150 12% 10%)"
  dark-popover-foreground: "hsl(105 14% 94%)"
  dark-primary: "hsl(155 24% 70%)"
  dark-primary-foreground: "hsl(165 25% 10%)"
  dark-secondary: "hsl(150 10% 17%)"
  dark-secondary-foreground: "hsl(105 14% 94%)"
  dark-muted: "hsl(150 9% 15%)"
  dark-muted-foreground: "hsl(125 8% 67%)"
  dark-accent: "hsl(154 14% 20%)"
  dark-accent-foreground: "hsl(105 14% 94%)"
  dark-destructive: "hsl(8 58% 56%)"
  dark-destructive-foreground: "hsl(30 25% 98%)"
  dark-border: "hsl(150 8% 23%)"
  dark-input: "hsl(150 8% 28%)"
  dark-ring: "hsl(155 24% 65%)"
  dark-chart-1: "hsl(155 34% 57%)"
  dark-chart-2: "hsl(22 60% 59%)"
  dark-chart-3: "hsl(204 49% 61%)"
  dark-chart-4: "hsl(44 60% 62%)"
  dark-chart-5: "hsl(282 33% 65%)"
  dark-sidebar-background: "hsl(153 14% 11%)"
  dark-sidebar-foreground: "hsl(105 14% 91%)"
  dark-sidebar-primary: "hsl(155 24% 70%)"
  dark-sidebar-primary-foreground: "hsl(165 25% 10%)"
  dark-sidebar-accent: "hsl(153 12% 19%)"
  dark-sidebar-accent-foreground: "hsl(105 14% 94%)"
  dark-sidebar-border: "hsl(150 8% 23%)"
  dark-sidebar-ring: "hsl(155 24% 65%)"
typography:
  headline:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.333
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-0.025em"
  section-title:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.5
    letterSpacing: "normal"
  body:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.714
    letterSpacing: "normal"
  control:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.25
    letterSpacing: "normal"
  label:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.333
    letterSpacing: "normal"
  navigation-label:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.68rem"
    fontWeight: 600
    lineHeight: 1.47
    letterSpacing: "0.08em"
rounded:
  none: "0"
  sm: "0.125rem"
  md: "0.25rem"
  lg: "0.375rem"
  pill: "9999px"
spacing:
  "1": "0.25rem"
  "1-5": "0.375rem"
  "2": "0.5rem"
  "3": "0.75rem"
  "4": "1rem"
  "5": "1.25rem"
  "6": "1.5rem"
  "8": "2rem"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
    typography: "{typography.control}"
    rounded: "{rounded.lg}"
    padding: "0.5rem 1rem"
    height: "2.75rem"
  button-outline:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    typography: "{typography.control}"
    rounded: "{rounded.lg}"
    padding: "0.5rem 1rem"
    height: "2.75rem"
  input:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "0.5rem 0.75rem"
    height: "2.75rem"
  register-panel:
    backgroundColor: "{colors.card}"
    textColor: "{colors.card-foreground}"
    rounded: "{rounded.none}"
    padding: "1rem"
  status-pending:
    backgroundColor: "hsl(169 32% 28% / 0.1)"
    textColor: "{colors.primary}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "0.25rem 0.625rem"
    height: "1.75rem"
  navigation-active:
    backgroundColor: "{colors.sidebar-accent}"
    textColor: "{colors.sidebar-accent-foreground}"
    typography: "{typography.control}"
    rounded: "{rounded.lg}"
    padding: "0.5rem"
    height: "2.5rem"
---

# Design System: ArchiBot

## Overview

**Creative North Star: "The Transfer Register"**

ArchiBot feels like a municipal transfer register refined into a modern personal admin tool: mineral stock, archival green dividers, oxide decision marks, and crisp ledger rules organize one document at a time. The interface is calm, factual, and document-centric rather than dashboard-like or decorative.

Routine review is deliberately fast and open. The current document, proposed changes, and next safe action dominate; technical evidence, advanced filters, maintenance, and danger controls remain available through progressive disclosure. New entity approvals deserve additional visual pause and explicit consequence language because they cross a higher-risk boundary.

Public Sans gives the register a polished conventional admin voice. Light and dark themes preserve the same semantic roles, compact geometry, and rule-led hierarchy rather than becoming separate visual identities.

**Key Characteristics:**
- Mineral-stock surfaces and archival-green structure with oxide reserved for destructive decisions.
- Crisp ledger dividers, restrained structural shadows, and a compact 0.375rem corner language.
- One dominant document workspace with routine decisions kept close and secondary evidence disclosed on demand.
- Conventional, accessible admin controls with 44px targets, strong focus, and non-color status cues.

## Colors

The light palette reads as green-cast mineral paper and ink; the dark palette becomes a deep archival desk while retaining the same semantic assignments.

### Primary
- **Archival Green:** Primary actions, active navigation, progress, links, and lightly washed changed rows. Its dark-theme counterpart becomes a pale mineral green so emphasis remains legible without glare.

### Secondary
- **Register Wash:** Quiet secondary controls and low-emphasis groupings that need more structure than the page background.

### Tertiary
- **Oxide Mark:** Destructive actions, failures, and danger boundaries only. Amber marks warnings, sky marks active work, violet marks recovery, and emerald marks completion; these status families never communicate without an icon and text label.

### Neutral
- **Mineral Stock:** The page background is green-cast rather than pure white; the slightly warmer card surface carries documents, forms, and register panels.
- **Ledger Ink:** Near-black green supplies body and heading contrast in light mode; the dark theme reverses to a soft mineral foreground rather than stark white.
- **Rule and Field:** Border and input roles create crisp ledger divisions. Muted roles carry unchanged facts, timestamps, help text, and secondary evidence.
- **Archive Rail:** Sidebar roles are distinct from the page roles so navigation reads as a divider rail, not another floating card.

### Named Rules
**The One Accent Rule.** Use archival green for primary decisions and active structure; reserve oxide for destructive or failure states rather than decoration.

**The Semantic Pairing Rule.** Every status combines a recognizable icon, explicit text, and semantic color; color alone is never the message.

## Typography

**Display Font:** Public Sans (with the implemented UI sans-serif and system fallbacks)
**Body Font:** Public Sans (with the implemented UI sans-serif and system fallbacks)

**Character:** Public Sans keeps the archive metaphor contemporary, neutral, and highly scannable. Weight, spacing, and ledger rules establish hierarchy; the system does not depend on a decorative display face.

### Hierarchy
- **Headline** (600, 1.5rem rising to 1.875rem at the small breakpoint, tight tracking): Page titles only, balanced above a ledger rule.
- **Title** (600, 1.25rem, tight tracking): The dominant queue or document action within a panel.
- **Section title** (600, 1rem): Panel headings and disclosure content headings.
- **Body** (400, 0.875rem, 1.5rem line-height): Descriptions, metadata, evidence, and form help, normally constrained to roughly 48rem.
- **Control** (500, 0.875rem): Buttons, navigation items, links, and field labels.
- **Label** (500, 0.75rem): Statuses, timestamps, counts, and compact evidence.
- **Navigation label** (600, 0.68rem, 0.08em tracking, uppercase): Review, Library, Monitor, and Configure group markers only.

### Named Rules
**The Register Voice Rule.** Use Public Sans and restrained weight changes for hierarchy; reserve monospace for literal operational identifiers, event names, and editable prompt content.

## Layout

The authenticated shell uses a 16rem archival rail on desktop, collapses to a 3rem icon rail, and becomes an 18rem off-canvas navigation sheet on narrow screens. The sticky top register bar is 3.5rem tall. Main content sits in a centered frame up to 96rem wide with 1rem side padding on mobile, 1.5rem from the small breakpoint, and 2rem from the large breakpoint.

The spacing rhythm is compact and regular: 0.375rem for field-label gaps, 0.5–0.75rem for related inline items, 1rem for default panel padding, 1.25rem for comfortable register sleeves, 1.5rem between major blocks, and 2rem beneath page headings. Ledger rows use horizontal rules and 1–1.25rem inset padding rather than isolated card grids.

The review detail is the signature responsive register layout. It stacks the source preview above the decision record on smaller screens, then becomes a 1.2fr / 0.8fr split at 80rem; the preview stays visible and the decision panel stays near the viewport edge. Filters and operation summaries progressively move from one column to two, three, or four only when their content has room. Dense evidence may scroll horizontally, but primary review content must wrap without horizontal page overflow.

**The One Document Rule.** Give the current document and its next safe action the dominant column; never let operational summaries compete with the review workspace.

**The Disclosure Rule.** Keep routine review content open, but place technical evidence, advanced filters, admin recovery, and danger controls behind clearly labeled native disclosures.

## Elevation & Depth

The system is flat and rule-led by default. Background, card, sidebar, borders, and inset washes establish most depth. Register panels add only a restrained structural shadow (`0 10px 30px -26px hsl(162 17% 11% / 0.45)`); the sticky decision sleeve uses the stronger but still compressed `0 18px 44px -24px hsl(162 17% 11% / 0.55)` so the next safe action remains visually attached to the document. Small control and shell shadows are permitted, while the sticky header uses a light background veil and subtle backdrop blur for continuity.

### Shadow Vocabulary
- **Register structure** (`0 10px 30px -26px hsl(162 17% 11% / 0.45)`): Default document and operations panels.
- **Decision sleeve** (`0 18px 44px -24px hsl(162 17% 11% / 0.55)`): Sticky accept/reject region only.
- **Inset shell:** A small structural shadow may separate the desktop content inset from the archive rail.

### Named Rules
**The Structural Shadow Rule.** Use borders and tonal layering first; add shadow only to clarify a panel, sticky decision boundary, or inset shell—not to make every container float.

## Shapes

The base corner is compact and gently squared (0.375rem), with derived 0.25rem and 0.125rem steps for nested controls and crisp register surfaces. Major register panels remain nearly square and depend on ledger rules; conventional controls, nested cards, and disclosures use the base radius. Full pills are reserved for status badges, compact counts, and section filters. Document previews clip inside the panel, progress tracks stay fully rounded, and one-pixel borders provide the recurring sleeve and ledger silhouette.

**The Crisp Sleeve Rule.** Prefer one bordered register surface with internal dividers over a cluster of separately rounded cards.

## Components

### Buttons
- **Shape:** Compact conventional control with a 0.375rem radius and a default 2.75rem height.
- **Primary:** Archival-green fill, mineral foreground, medium-weight label, and 1rem horizontal padding; use for the next safe action.
- **Hover / Focus:** Hover darkens the fill slightly. Keyboard focus uses a two-pixel semantic ring with offset, never a color-only change.
- **Secondary / Outline / Ghost:** Secondary uses the register wash; outline uses the page surface and field border; ghost is reserved for chrome and navigation. Destructive uses oxide and requires explicit consequence copy. Disabled controls retain their geometry at half opacity.

### Chips
- **Style:** Statuses are compact bordered pills with a 1.75rem minimum height, 0.625rem horizontal padding, a 0.875rem icon, and a short text label. Section filters may use a similarly compact pill, but do not borrow failure colors for selection.
- **State:** Completion uses check, failure uses x, warning uses triangle, active/recovery uses rotating arrows, queued uses clock, paused uses pause, and cancelled uses ban. Unknown status uses a dashed-circle icon and neutral styling.

### Cards / Containers
- **Corner Style:** Register panels are nearly square; nested conventional cards use the 0.375rem base corner.
- **Background:** Card roles sit on the mineral page role; changed rows receive only a very light primary wash.
- **Shadow Strategy:** Follow the Structural Shadow Rule.
- **Border:** One-pixel semantic rules define panel edges, headers, ledger rows, and table records.
- **Internal Padding:** 1rem by default and 1.25rem on roomier small-screen breakpoints; 1.5rem is reserved for a dominant introductory panel.

### Inputs / Fields
- **Style:** A 2.75rem-high page-colored field with a one-pixel input border, 0.375rem radius, 0.75rem horizontal padding, and a restrained small shadow.
- **Focus:** Two-pixel ring and offset using the semantic ring role.
- **Error / Disabled:** Errors use oxide text and adjacent explanatory copy. Disabled inputs retain labels and boundaries, lower opacity, and never rely on placeholder text as the label.

### Navigation
- **Style:** Group labels are tiny uppercase register markers; items are 2.5rem high with a 1rem icon and medium text. Hover and active states use the sidebar accent role; active state also increases label weight. Desktop navigation can collapse to icons with tooltips, while mobile navigation becomes an off-canvas sheet with the same groups and order.

### Register Disclosure
- **Style:** A bordered card surface with a minimum 2.75rem summary row, 1rem horizontal inset, medium label, and plus/minus state marker. The disclosed body begins behind a full-width ledger rule.
- **Behavior:** Use native summary semantics and keep the label consequence-oriented: evidence, filters, maintenance, recovery, or danger.

### Review Register
- **Style:** Queue records are separated ledger rows rather than loose cards. Detail review keeps preview and proposed changes together; changed values are medium-weight on a light primary wash, while prior values appear smaller, muted, and struck through.
- **Decision:** Accept and reject remain visually grouped in a sticky decision sleeve, with explicit Paperless consequences. Routine review may advance directly to the next document, but new entity approvals require extra care and must not be visually collapsed into a casual bulk action.

## Do's and Don'ts

### Do:
- Do preserve semantic light and dark role pairs; let inherited tokens change the theme rather than hard-coding a second component style.
- Do keep primary buttons, inputs, selects, disclosure summaries, and checkbox hit areas at least 44px tall or wide.
- Do use ledger rules, wrapping metadata, and progressive disclosure to simplify dense operational evidence.
- Do pair every status color with its implemented icon and explicit text label.
- Do keep the source document, changed metadata, and accept/reject consequences visible together when space permits.
- Do slow the workflow at new correspondent, document type, or tag approvals with explicit labels and consequence copy.

### Don't:
- Don't turn the page into a generic grid of floating dashboard cards or give every container a shadow.
- Don't use pure white page chrome, oversized radii, gradients, glass effects, or decorative color outside the implemented semantic roles.
- Don't expose pipeline, webhook, recovery, and danger controls at the same hierarchy as routine document review.
- Don't use oxide for ordinary emphasis, archival green for destructive actions, or any status color without icon and text.
- Don't shrink controls below 44px, hide keyboard focus, or make unchanged facts as prominent as changed values.
- Don't collapse higher-risk new entity approvals into the fast bulk-review treatment.
