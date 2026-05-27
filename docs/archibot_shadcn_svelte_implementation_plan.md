# ArchiBot shadcn-svelte UI Implementation Plan

## 1. Ziel

ArchiBot soll ein modernes, wartbares Admin-/Operations-UI erhalten, ohne die bestehende Laravel-Processing-, Queue-, Job- oder Integrationslogik umzubauen.

Der bestehende Stack bleibt maßgeblich:

- Laravel bleibt Backend, Auth-, Job-, Queue- und Service-Schicht.
- Inertia bleibt die Brücke zwischen Laravel und Frontend.
- Svelte bleibt das Frontend-Framework.
- Tailwind bleibt das Styling-System.
- shadcn-svelte wird als Komponenten- und Designsystem-Basis eingeführt.

## 2. Nicht-Ziele

Diese Umsetzung darf ausdrücklich nicht zu einer Architektur-Migration werden.

Nicht erlaubt:

- Kein Wechsel zu SvelteKit als eigener App-Router.
- Keine neue SPA parallel zu Laravel/Inertia.
- Kein AdminLTE, Bootstrap oder Flowbite als zusätzliche UI-Hauptabhängigkeit.
- Keine Änderung an Laravel Jobs, Services, Models, Migrations, Queue-Konfiguration oder Processing-Logik.
- Keine Änderung an Paperless-, Ollama-, LLM-, Embedding- oder Dokumentenverarbeitung, außer ein späterer expliziter Backend-Scope erlaubt das.
- Keine Einführung von Retry-, Delete- oder Mutations-Actions im UI, solange die vorhandenen Backend-Verträge dafür nicht geprüft und freigegeben sind.

## 3. Leitentscheidung

shadcn-svelte wird nicht als fertiges Theme verstanden, sondern als kontrollierte Komponentenbasis.

Ziel ist:

```text
ArchiBot-spezifisches UI
  auf Basis von shadcn-svelte-Komponenten
  innerhalb der bestehenden Laravel/Inertia/Svelte-App
  ohne Backend-Processing-Umbau
```

## 4. Zielstruktur im Frontend

Empfohlene Zielstruktur unter `laravel/resources/js`:

```text
laravel/resources/js/
  components/
    ui/
      button/
      card/
      badge/
      separator/
      dropdown-menu/
      sheet/
      tooltip/
      alert/
      progress/
      tabs/
      table/
      dialog/
      input/
      label/
      select/
      switch/
      textarea/
      scroll-area/
    archibot/
      AppShell.svelte
      AppSidebar.svelte
      AppHeader.svelte
      AppBreadcrumbs.svelte
      ThemeToggle.svelte
      QueueHealthCard.svelte
      JobStatusBadge.svelte
      ProcessingStatusBadge.svelte
      ConnectionStatus.svelte
      DocumentStatusCard.svelte
      PipelineCard.svelte
      LogViewer.svelte
      EmptyState.svelte
      ErrorState.svelte
  layouts/
    AppLayout.svelte
  pages/
    Dashboard.svelte
    Jobs/
      Index.svelte
      Show.svelte
    Documents/
      Index.svelte
      Show.svelte
    Settings/
      Index.svelte
    System/
      Index.svelte
```

Hinweis: Die konkrete bestehende Seitenstruktur im Repo ist vor Umsetzung zu prüfen. Der Agent soll bestehende Pages nicht blind ersetzen, sondern die vorhandenen Inertia-Pages schrittweise in das neue Layout einhängen.

## 5. Designprinzipien

ArchiBot soll wie ein lokales AI-/Dokumenten-Operations-Tool wirken, nicht wie ein generisches Admin-Template.

Designrichtung:

- Ruhiges, technisches SaaS-/Admin-UI.
- Klare Sidebar.
- Header mit Kontext, Status und User/Theme-Actions.
- Card-basierte Statusübersichten.
- Dezente Badges für Queue-, Job-, Document- und Connection-Status.
- Gute Lesbarkeit für Logs und Fehlermeldungen.
- Dark Mode von Beginn an berücksichtigen.
- Keine übertriebenen Animationen.
- Keine Marketing-Landingpage-Optik.

## 6. Phasenübersicht

### Phase UI-0: Bestandsaufnahme und Guardrails

Ziel: Vor dem Umbau den aktuellen Frontend-Zustand dokumentieren und harte Grenzen setzen.

Aufgaben:

1. Bestehende Frontend-Struktur prüfen:
   - `laravel/resources/js/**`
   - `laravel/resources/css/**`
   - vorhandene Inertia-Pages
   - vorhandene Layouts
   - vorhandene Komponenten
2. Bestehende Tests/Checks lokal ausführen.
3. Falls kein UI-Guardrail-Dokument existiert, eine kurze Notiz im Repo ergänzen, z. B. `docs/ui-implementation-guardrails.md` oder ein Abschnitt in vorhandener Entwicklerdokumentation.
4. Dokumentieren, dass der erste Scope rein frontendbezogen ist.

Erlaubte Dateien:

- `docs/**`, falls ein Guardrail-Dokument ergänzt wird.
- Keine App-Logik.

Nicht erlaubt:

- Keine Änderung an Runtime-Code.
- Keine Änderung an Jobs, Services, Models oder Migrations.

Akzeptanzkriterien:

- Aktuelle Frontendstruktur ist bekannt.
- Bestehende Checks laufen oder bekannte Fehler sind dokumentiert.
- UI-Scope-Grenzen sind schriftlich festgehalten.

---

### Phase UI-1: shadcn-svelte Basis und Theme-Tokens

Ziel: Minimale shadcn-svelte-kompatible Komponentenbasis und Theme-Struktur einführen.

Empfohlene Komponenten für den Start:

```text
button
card
badge
separator
dropdown-menu
sheet
tooltip
alert
progress
```

Aufgaben:

1. Prüfen, ob shadcn-svelte bereits initialisiert ist.
2. Falls nicht, shadcn-svelte passend zur vorhandenen Svelte/Tailwind/Vite-Struktur initialisieren.
3. Nur die benötigten Komponenten hinzufügen.
4. Theme-Variablen zentral in der bestehenden CSS-Struktur ergänzen.
5. Utility-Funktionen wie `cn` konsistent ablegen, falls noch nicht vorhanden.
6. Keine pauschale Komponentenflut einführen.

Erlaubte Dateien:

- `laravel/resources/js/components/ui/**`
- `laravel/resources/js/lib/**`, falls für UI-Utilities erforderlich
- `laravel/resources/css/**`
- `laravel/package.json`
- Lockfile, falls Dependency-Änderung notwendig ist

Nicht erlaubt:

- Keine Änderung an Backend-Ordnern.
- Keine Änderung an Routes.
- Keine neue SvelteKit-Struktur.

Akzeptanzkriterien:

- Build läuft.
- Typecheck läuft.
- Format-Check läuft.
- Mindestens Button, Card und Badge können in einer bestehenden Page verwendet werden.
- Keine visuelle Regression, die Login oder Navigation unbenutzbar macht.

---

### Phase UI-2: AppShell und Layout

Ziel: Ein modernes App-Grundlayout einführen, ohne vorhandene Pages fachlich umzubauen.

Neue/fachliche Komponenten:

```text
components/archibot/AppShell.svelte
components/archibot/AppSidebar.svelte
components/archibot/AppHeader.svelte
components/archibot/AppBreadcrumbs.svelte
components/archibot/ThemeToggle.svelte
layouts/AppLayout.svelte
```

Navigation v1:

```text
Dashboard
Documents
Jobs
Settings
System
```

Aufgaben:

1. AppShell mit Sidebar, Header und Main Content Area bauen.
2. Sidebar responsiv machen:
   - Desktop: feste Sidebar.
   - Mobile/kleine Breite: Sheet/Drawer.
3. Header bauen mit:
   - Seitentitel/Breadcrumbs.
   - optionalem Statusbereich.
   - Theme Toggle.
   - User-/Account-Menü, falls vorhandene Daten verfügbar sind.
4. Bestehende Inertia-Pages in das neue Layout einhängen.
5. Existing functionality must remain available.

Erlaubte Dateien:

- `laravel/resources/js/components/**`
- `laravel/resources/js/layouts/**`
- `laravel/resources/js/pages/**`, nur Layout-Einhängung
- `laravel/resources/css/**`

Nicht erlaubt:

- Keine Änderung an Laravel Controller-/Route-Logik, außer eine bestehende Page benötigt nur einen bereits vorhandenen Routennamen.
- Keine Backend-Datenmodelle ändern.

Akzeptanzkriterien:

- Alle vorhandenen Hauptseiten sind weiterhin erreichbar.
- AppShell ist responsiv nutzbar.
- Sidebar markiert aktive Route, soweit sauber möglich.
- Dark/Light Mode funktioniert oder ist sauber vorbereitet.
- Keine Backend-Änderungen im Diff.

---

### Phase UI-3: Dashboard Refresh

Ziel: Dashboard als Operations-Zentrale aufbauen.

Dashboard v1 soll anzeigen:

```text
Top row:
- Queue Status
- Failed Jobs
- Pending Documents
- Ollama/Paperless Connection Status

Middle:
- Recent Documents / Recent Processing Runs
- Processing Pipeline Overview

Bottom:
- Recent Errors / Log Summary
```

Neue/fachliche Komponenten:

```text
QueueHealthCard.svelte
ConnectionStatus.svelte
ProcessingStatusBadge.svelte
DocumentStatusCard.svelte
PipelineCard.svelte
EmptyState.svelte
ErrorState.svelte
```

Aufgaben:

1. Bestehende Dashboard-Daten prüfen.
2. Wenn Daten fehlen, keine neuen Backend-Abfragen bauen; stattdessen saubere Empty States verwenden.
3. Dashboard mit Cards und Badges neu strukturieren.
4. Statusfarben konsistent definieren:
   - Success/OK
   - Warning/Degraded
   - Error/Failed
   - Neutral/Unknown
5. Keine Actions einführen, die Backend-Mutationen auslösen.

Erlaubte Dateien:

- `laravel/resources/js/components/**`
- `laravel/resources/js/pages/**`
- `laravel/resources/css/**`

Nicht erlaubt:

- Keine neuen Jobs.
- Keine neuen Migrations.
- Keine neuen Processing-Endpunkte.
- Keine Retry-/Delete-Actions.

Akzeptanzkriterien:

- Dashboard wirkt als klare Statusübersicht.
- Fehlende Daten führen zu Empty States, nicht zu kaputten Screens.
- Komponenten sind wiederverwendbar.
- Checks laufen.

---

### Phase UI-4: Jobs Overview und Log Viewer

Ziel: Jobs und Pipeline-Ausführung visuell besser kontrollierbar machen, ohne Ausführungslogik zu ändern.

Jobs-Seite v1:

```text
Tabs:
- All
- Pending
- Running
- Failed
- Done

Table columns:
- Job
- Related document
- Status
- Queue
- Attempts
- Last error summary
- Updated at
- Actions
```

Actions v1:

```text
View details
View log
```

Nicht in v1:

```text
Retry
Delete
Cancel
Force run
```

Neue/fachliche Komponenten:

```text
JobStatusBadge.svelte
LogViewer.svelte
ErrorState.svelte
EmptyState.svelte
```

Zusätzliche UI-Komponenten:

```text
table
tabs
dialog oder sheet
scroll-area
```

Aufgaben:

1. Bestehende Jobs-/Statusdaten prüfen.
2. Jobs-Liste mit Tabelle oder Card/Table-Hybrid neu darstellen.
3. Status-Badges einheitlich verwenden.
4. Detailansicht als Dialog oder Sheet bauen.
5. LogViewer mit Monospace, Scrollbereich und Copy-Möglichkeit vorbereiten.
6. Keine Backend-Mutationen einführen.

Erlaubte Dateien:

- `laravel/resources/js/components/**`
- `laravel/resources/js/pages/Jobs/**`
- `laravel/resources/css/**`

Nicht erlaubt:

- Keine Queue-Ausführungslogik ändern.
- Keine Failed-Job-Verarbeitung ändern.
- Keine Retry-Actions ohne separates Ticket.

Akzeptanzkriterien:

- Jobs sind visuell filterbar oder zumindest klar gruppiert.
- Logs/Fehler sind lesbar.
- Kein Backend-Verhalten wurde geändert.
- Checks laufen.

---

### Phase UI-5: Documents Overview

Ziel: Dokumente und deren Verarbeitungsstatus übersichtlicher darstellen.

Documents-Seite v1:

```text
Columns / card fields:
- Title or filename
- Paperless document id, if available
- Processing status
- Last pipeline step
- Tags/correspondent/type, if already available
- Last processed timestamp
- Actions
```

Actions v1:

```text
View details
Open external Paperless link, if already available and safe
```

Nicht in v1:

```text
Reprocess
Delete
Retag
Send to LLM
```

Aufgaben:

1. Bestehende Dokumentdaten prüfen.
2. Liste als Table oder responsive Card-Grid darstellen.
3. Processing-Status visuell vereinheitlichen.
4. Empty State für “no documents yet”.
5. Error State für “Paperless not reachable”, falls diese Information bereits vorhanden ist.

Erlaubte Dateien:

- `laravel/resources/js/components/**`
- `laravel/resources/js/pages/Documents/**`
- `laravel/resources/css/**`

Nicht erlaubt:

- Keine Paperless-Service-Änderung.
- Keine Dokumentenverarbeitungslogik ändern.
- Keine neuen API-Verträge ohne separates Ticket.

Akzeptanzkriterien:

- Dokumentenstatus ist auf einen Blick erkennbar.
- Fehlende optionale Daten brechen die UI nicht.
- Checks laufen.

---

### Phase UI-6: Settings Refresh

Ziel: Einstellungen als klare, gruppierte Admin-Seite darstellen.

Settings-Gruppen:

```text
Paperless
- Base URL
- API connectivity status
- Last successful check

Ollama / LLM
- Base URL
- Chat model
- Embedding model, if applicable
- Connectivity status

Processing
- Queue name
- Max attempts
- Enabled processing steps, if already configurable

Application
- Version/build info, if already available
- Environment indicator, if already available
```

Wichtig: Wenn Settings derzeit noch nicht speicherbar sind, nur read-only anzeigen oder mit klaren Disabled States arbeiten.

Zusätzliche UI-Komponenten:

```text
input
label
select
switch
textarea
alert
card
button
```

Aufgaben:

1. Bestehende Settings-/Config-Daten prüfen.
2. Settings in Cards gruppieren.
3. Read-only vs editable klar unterscheiden.
4. Keine neuen Secrets im Frontend anzeigen.
5. Keine API Keys im Klartext rendern.
6. Test-Connection-Buttons nur einführen, wenn Backend-Endpunkte bereits existieren.

Erlaubte Dateien:

- `laravel/resources/js/components/**`
- `laravel/resources/js/pages/Settings/**`
- `laravel/resources/css/**`

Nicht erlaubt:

- Keine `.env`-Manipulation.
- Keine Secrets im UI anzeigen.
- Keine neuen Backend-Endpunkte ohne separates Ticket.

Akzeptanzkriterien:

- Settings sind verständlich gruppiert.
- Secrets werden nicht offengelegt.
- Read-only Daten sind klar als read-only erkennbar.
- Checks laufen.

---

## 7. UI-Komponenten-Konventionen

### Generische Komponenten

Generische Komponenten liegen unter:

```text
laravel/resources/js/components/ui/**
```

Regeln:

- Möglichst nah an shadcn-svelte-Konventionen bleiben.
- Keine fachliche ArchiBot-Logik in `components/ui`.
- Keine API Calls in `components/ui`.
- Keine Inertia-spezifische Fachlogik in `components/ui`, außer Link-/Button-Varianten sind bewusst dafür gebaut.

### Fachliche Komponenten

Fachliche Komponenten liegen unter:

```text
laravel/resources/js/components/archibot/**
```

Regeln:

- Dürfen ArchiBot-Begriffe enthalten: Job, Queue, Document, Pipeline, Paperless, Ollama.
- Dürfen Props für fachliche Statuswerte annehmen.
- Sollen möglichst pure Darstellungskomponenten bleiben.
- Keine Backend-Mutationen ohne explizites Ticket.

## 8. Statusmodell für UI

Einheitliche Statuswerte verwenden.

Empfohlene Mapping-Basis:

```text
success / ok / completed / healthy
  -> positive Badge/Card state

warning / degraded / pending / unknown
  -> warning or neutral state

error / failed / unreachable
  -> destructive/error state

running / processing / active
  -> active/in-progress state
```

Die konkrete Farbgebung soll über Komponentenvarianten erfolgen, nicht über verstreute Tailwind-Klassen in jeder Page.

## 9. Accessibility- und UX-Mindeststandards

- Buttons brauchen erkennbare Labels oder `aria-label`.
- Status darf nicht nur über Farbe erkennbar sein.
- Dialoge/Sheets müssen per Tastatur bedienbar sein.
- Tabellen brauchen sinnvolle Header.
- Logs müssen scrollbar sein und dürfen Layouts nicht sprengen.
- Mobile Layout darf nicht unbenutzbar werden.
- Dark Mode darf keine unlesbaren Kontraste erzeugen.

## 10. Test- und Check-Strategie

Nach jeder Phase ausführen:

```bash
cd laravel
npm run format:check
npm run lint:check
npm run types:check
npm run build
composer lint:check
php artisan test
```

Wenn das Projekt bereits einen kombinierten Check anbietet:

```bash
cd laravel
composer ci:check
```

Bei reinen UI-Änderungen darf der Agent keine Backend-Testfehler ignorieren, aber er soll klar unterscheiden:

- Fehler durch eigene Änderung: fixen.
- Bereits vorher bestehende Fehler: dokumentieren und nicht im UI-PR versteckt beheben, außer explizit erlaubt.

## 11. Branch- und PR-Strategie

Empfohlene Branches:

```text
ui/shadcn-foundation
ui/archibot-app-shell
ui/dashboard-refresh
ui/jobs-refresh
ui/documents-refresh
ui/settings-refresh
```

Empfohlene PR-Reihenfolge:

1. PR 1: shadcn foundation + theme tokens
2. PR 2: AppShell + Layout
3. PR 3: Dashboard refresh
4. PR 4: Jobs overview + log viewer
5. PR 5: Documents overview
6. PR 6: Settings refresh

Jeder PR soll klein genug bleiben, dass ein Review sinnvoll möglich ist.

## 12. Stop-Regeln für Coding Agents

Der Agent muss stoppen und menschliche Freigabe verlangen, wenn:

- Eine Backend-Route geändert werden müsste.
- Eine Migration nötig erscheint.
- Ein neuer Controller/Endpoint nötig erscheint.
- Queue-/Job-Ausführungslogik geändert werden müsste.
- Secrets oder API Keys im UI sichtbar würden.
- Bestehende Tests wegen Backend-Verhalten angepasst werden müssten.
- Ein Dependency-Konflikt entsteht, der größere Upgrades erfordert.
- shadcn-svelte-Initialisierung die bestehende Tailwind-/Vite-Struktur inkompatibel verändern würde.

## 13. Konkreter Startauftrag für den ersten Agent-Lauf

```text
Task: Introduce shadcn-svelte UI foundation for ArchiBot

Repository: pfriedrich84/archibot
Working directory: laravel

Goal:
Introduce a minimal shadcn-svelte-compatible UI foundation for the existing Laravel + Inertia + Svelte + Tailwind frontend.

Scope:
- Inspect the existing frontend structure under laravel/resources/js and laravel/resources/css.
- Add or align minimal UI utilities needed for shadcn-svelte-style components.
- Add initial UI components: button, card, badge, separator, alert, progress.
- Add or align theme tokens for light/dark mode.
- Add a small internal demo usage on an existing safe page or a temporary internal component, but do not create a new app architecture.

Allowed files:
- laravel/resources/js/**
- laravel/resources/css/**
- laravel/package.json and lockfile only if required
- docs/** only if documenting UI guardrails

Forbidden files/areas:
- laravel/app/Jobs/**
- laravel/app/Services/**
- laravel/app/Models/**
- laravel/database/**
- laravel/routes/**
- Queue configuration
- Processing logic
- Paperless/Ollama integration logic

Hard constraints:
- Do not introduce SvelteKit routing.
- Do not introduce Bootstrap/AdminLTE/Flowbite as a primary UI dependency.
- Do not change backend behavior.
- Do not expose secrets.

Validation:
Run:
- npm run format:check
- npm run lint:check
- npm run types:check
- npm run build
- composer ci:check if feasible

Output:
- Summarize changed files.
- Confirm no backend processing files were changed.
- Report validation results.
- If any check fails, explain whether it is caused by this change or pre-existing.
```

## 14. Zweiter Agent-Auftrag nach erfolgreichem Foundation-PR

```text
Task: Build ArchiBot AppShell using shadcn-svelte-style components

Goal:
Create a modern AppShell for the existing Inertia/Svelte frontend.

Scope:
- Add AppShell, AppSidebar, AppHeader, AppBreadcrumbs, ThemeToggle.
- Add AppLayout and wire existing pages into it.
- Add navigation for Dashboard, Documents, Jobs, Settings, System where matching pages/routes already exist.
- Use disabled/placeholder navigation only when a page does not exist yet.
- Preserve existing page functionality.

Allowed files:
- laravel/resources/js/components/**
- laravel/resources/js/layouts/**
- laravel/resources/js/pages/** only for layout integration
- laravel/resources/css/**

Forbidden:
- No backend changes.
- No route changes unless strictly necessary and separately reported.
- No processing changes.

Validation:
- npm run format:check
- npm run lint:check
- npm run types:check
- npm run build
- composer ci:check if feasible

Acceptance criteria:
- Existing pages render inside the new layout.
- Desktop sidebar works.
- Mobile navigation works or degrades safely.
- Theme toggle is present or explicitly prepared.
- No backend files changed.
```

## 15. Review-Checkliste für jeden PR

- [ ] Betrifft der PR wirklich nur den freigegebenen Scope?
- [ ] Wurden keine Backend-Jobs, Services, Models, Migrations oder Queue-Konfigurationen geändert?
- [ ] Wurde keine zweite Frontend-App eingeführt?
- [ ] Wurde kein Bootstrap/AdminLTE/Flowbite eingeführt?
- [ ] Sind UI-Komponenten sauber zwischen `components/ui` und `components/archibot` getrennt?
- [ ] Sind Empty States vorhanden?
- [ ] Sind Error States vorhanden?
- [ ] Sind Statuswerte nicht nur farblich erkennbar?
- [ ] Funktionieren Format, Lint, Typecheck und Build?
- [ ] Wurden fehlgeschlagene Checks sauber erklärt?
- [ ] Sind Screenshots oder kurze Vorher/Nachher-Beschreibung im PR enthalten?

## 16. Empfehlung

Starte mit zwei kleinen PRs:

1. `ui/shadcn-foundation`
2. `ui/archibot-app-shell`

Erst danach Dashboard, Jobs, Documents und Settings anfassen.

Damit bekommt ArchiBot schnell ein deutlich besseres UI-Fundament, ohne dass die wertvolle Laravel-Jobsteuerung und Processing-Architektur gefährdet werden.

