# AGENTS.md — wicket-admin-org-roster

## Project Overview

`wicket-admin-org-roster` is a WordPress admin plugin for managing organization rosters and person-to-organization relationships via the Wicket MDP (Member Data Platform) API. It provides bulk upload, validation, duplicate resolution, and sync workflows for administrators.

Ticket prefix: **AORM**. Plugin slug: **aorm**.

## Project Structure & Module Organization

```
wicket-admin-org-roster/
├── wicket-admin-org-roster.php   # Plugin bootstrap (constants, autoloader, singleton)
├── composer.json                  # PSR-4 autoload under WicketAORM\
├── package.json                   # @wordpress/scripts, @wordpress/element, @wordpress/components
├── phpcs.xml                      # PSR-12
├── webpack.config.js              # Extends @wordpress/scripts default config
│
├── src/                           # PSR-4 root → WicketAORM\
│   ├── Main.php                   # Singleton orchestrator (admin menus, hooks, init)
│   ├── Assets.php                 # Enqueue React bundles + WP admin styles
│   ├── Database/
│   │   ├── Migrator.php           # Schema create/upgrade on activation
│   │   └── StagedRecordsTable.php # wp_wicket_orm_staged_records CRUD
│   ├── Rest/
│   │   ├── RestController.php          # Base controller (namespace, permissions)
│   │   ├── RosterController.php        # Roster list/detail endpoints
│   │   ├── UploadController.php        # File upload + parse → staged records
│   │   ├── StagedRecordController.php  # GET /staged-records/{session_id} — validation review
│   │   ├── UploadStatusController.php  # GET /uploads/{id}/status — matching progress
│   │   ├── UploadStagedController.php  # GET /uploads/{id}/staged — categorised review (AORM-8.1)
│   │   ├── ActiveSessionController.php # GET /rosters/{org}/{membership}/session
│   │   ├── IndividualController.php    # Individual person add flow
│   │   └── SyncController.php         # MDP sync (create/merge/update)
│   ├── Services/
│   │   ├── FileParserService.php  # CSV → normalized rows
│   │   ├── ValidationService.php  # Row-level validation rules
│   │   ├── MatchingService.php    # Duplicate detection against MDP
│   │   ├── SyncService.php        # Staged → MDP (relationships, persons)
│   │   └── MdpClient.php          # Thin wrapper around wicket_api_client()
│   └── Admin/
│       ├── MenuPage.php           # Register all admin menu/submenu pages
│       ├── RosterListPage.php     # Org roster listing (WP_List_Table)
│       ├── SettingsPage.php       # Plugin settings (WordPress Settings API)
│       └── views/                 # PHP templates for admin page rendering
│
├── resources/js/                  # React source (compiled by @wordpress/scripts)
│   ├── roster-detail/             # Single org roster detail island
│   ├── upload-review/             # Upload review + validation island
│   ├── duplicate-resolution/      # Match resolution island
│   ├── sync-progress/             # Sync progress island
│   ├── components/                # Shared UI built with @wordpress/components
│   ├── hooks/                     # Custom React hooks (useRestApi, useStagedRecords)
│   └── utils/                     # apiFetch wrapper, helpers
│
├── build/                         # Compiled output (gitignored)
└── languages/                     # i18n .pot/.po/.mo
```

## Key Architectural Decisions

- **Classic PHP admin pages**: The Org Roster listing, configuration, and settings pages are standard WordPress admin pages rendered in PHP. Roster listing uses `WP_List_Table`. Settings use the WordPress Settings API. No React on these pages.
- **React islands (interactive workflows only)**: React is used exclusively for complex interactive screens that require real-time state management — roster detail view, upload review/validation, duplicate resolution, and sync progress. Each mounts into a `<div id="aorm-{feature}">` container on an otherwise PHP-rendered admin page. Each island has its own `@wordpress/scripts` entry point.
- **@wordpress/components**: All React UI must use `@wordpress/components` for consistent WP admin look and feel. Do not introduce Material UI, Chakra, or other component libraries.
- **RecordsTable component** (`resources/js/components/upload/RecordsTable.js`): Reusable table used inside every validation-review accordion panel (AORM-8.3). Provides client-side sorting (first/last/email columns), `SearchControl` filtering across all records, and 10-per-page pagination. Category-specific columns (e.g. `# Matches`, `Previous Category`) are injected via the `extraColumns` prop. Exports `PAGE_SIZE = 10` and `BASE_COLUMNS` constants. AORM-8B.2 adds optional row selection: pass `selectable={true}`, `selectedIds` (controlled `Set<number>`), and `onSelectionChange` callback to enable a `CheckboxControl` checkbox column prepended before the base columns. The header checkbox selects/deselects all currently-filtered records (all pages) with indeterminate state when partially selected (set via `useRef` + DOM `querySelector` since `CheckboxControl` does not expose an `indeterminate` prop). Selected rows get an `aorm-records-table__row--selected` CSS class. Exports `SELECTABLE_COL_KEY = 'select'` constant for test assertions.
- **ReadyToSyncPanel component** (`resources/js/components/upload/ReadyToSyncPanel.js`): Orchestrates the entire content area for the "Ready to Sync" PanelBody in the accordion. AORM-8.4 adds a session header (`<dl>`) showing the uploaded file name (from React's `selectedFile` state — omitted when null, e.g. after a page reload) and the action type label. Exports `ACTION_TYPE_LABELS` constant mapping `'add'`→"Add to Roster" and `'replace'`→"Replace Roster". AORM-8.5 adds a "Records being added" section with a `RecordsTable` (Status extra column) filtered to records with `record_status` in `['new_record', 'exact_match', 'merging_to_record', 'already_on_roster']`. Exports `RECORD_STATUS_LABELS` (status→label map) and `ADDED_STATUSES` (the filtered set) for test assertions. AORM-8.6 adds a "Records being removed" section (rendered only when `actionType === 'replace'`) with a `RecordsTable` filtered to records with `record_status === 'remove_existing'`. Exports `REMOVED_STATUSES = ['remove_existing']`. The removed-records table uses only the three base columns (no Status extra column — all rows share the same status). Props: `records` (all ready_to_sync records), `actionType` (from the staged API response, captured in `ValidationReviewStep` state), `fileName` (from `selectedFile?.name`, may be null). AORM-8B.1 adds an "Actions" extra column to the added-records table with three context-sensitive per-row actions: **Discard** (all added statuses — calls `PATCH /wicket-aorm/v1/staged-records/{id}` with `{category:'discard'}`, endpoint built in AORM-8B.8; `discardingIds` Set state tracks in-flight requests; a dismissible `Notice` surfaces errors); **See Details** (`merging_to_record` only — fires the `onOpenReviewModal(record)` prop callback, wired to the Review Match modal in AORM-8B.10); **View in MDP** (`exact_match` / `already_on_roster` only — `ExternalLink` to `{appEndpoint}/people/{matched_persons[0].uuid}`). Exports `SEE_DETAILS_STATUSES = ['merging_to_record']` and `VIEW_IN_MDP_STATUSES = ['exact_match', 'already_on_roster']` constants. New optional props: `onOpenReviewModal(record)`, `onRecordDiscarded()`. The `matched_persons` field (added to the staged records API response in AORM-8B.1 via `StagedRecordsTable::getGroupedByCategory()`) carries the decoded MDP candidate array (`{uuid, name, email, given_name, family_name}`); it is `null` when no matches were stored (e.g. `remove_existing` rows). `ValidationReviewStep` passes `onRecordDiscarded={refetchStaged}` where `refetchStaged` increments a `refetchKey` state to re-trigger the `useEffect` fetch without remounting. AORM-8B.2 adds bulk selection to the "Records being added" table: `selectedAddedIds` (`Set<number>`) state is passed as `selectedIds` to the selectable `RecordsTable`; a bulk action toolbar (`aorm-rts-bulk-toolbar`) renders above the table whenever `selectedAddedIds.size > 0`, showing a selected-count label and two buttons — **Sync to MDP** (fires `onSyncSelected(ids)` prop, wired in AORM-9) and **Discard** (calls `handleBulkDiscard` which uses `Promise.allSettled` to PATCH all selected IDs in parallel; partial failures show a dismissible `Notice` and narrow the selection to failed IDs only). New prop: `onSyncSelected?: (ids: number[]) => void`. New state: `selectedAddedIds`, `isBulkDiscarding`, `bulkDiscardError`. AORM-8B.3 adds an "Actions" extra column to the removed-records table with two per-row actions: **View in MDP** (all `remove_existing` records — `ExternalLink` to `{appEndpoint}/people/{raw_data.person_uuid}`; omitted when appEndpoint or person_uuid is unavailable; uses `raw_data.person_uuid` not `matched_persons` since `matched_persons` is always null for synthetic removal rows); **Discard Removal** (all `remove_existing` records — calls `PATCH /wicket-aorm/v1/staged-records/{id}` with `{category:'discard'}`, endpoint built in AORM-8B.8; `discardingRemovedIds` Set state tracks in-flight requests; a dismissible `Notice` surfaces errors; calls `onRecordDiscarded?()` on success). New exported constant: `VIEW_IN_MDP_REMOVAL_STATUSES = ['remove_existing']` for test assertions. New state: `discardingRemovedIds`, `discardRemovedError`.
- **PossibleMatchPanel component** (`resources/js/components/upload/PossibleMatchPanel.js`): Renders the "Possible Match" PanelBody content (AORM-8.7). Wraps a single `RecordsTable` with a "# Matches" `extraColumns` entry that displays each record's `match_count`. Display only — no View Match action (deferred to AORM-8B). Exports `POSSIBLE_MATCH_STATUS = 'possible_match'` and `MATCHES_COLUMN_LABEL` constants for test assertions. Props: `records` (all possible_match staged records).
- **ProbableMatchPanel component** (`resources/js/components/upload/ProbableMatchPanel.js`): Renders the "Probable Match" PanelBody content (AORM-8.8). Identical in structure to `PossibleMatchPanel` — wraps a single `RecordsTable` with a "# Matches" `extraColumns` entry displaying each record's `match_count`. Display only — no View Match action (deferred to AORM-8B). Exports `PROBABLE_MATCH_STATUS = 'probable_match'` and `MATCHES_COLUMN_LABEL` constants for test assertions. Props: `records` (all probable_match staged records).
- **ManualUpdatePanel component** (`resources/js/components/upload/ManualUpdatePanel.js`): Renders the "Manual Updates" PanelBody content (AORM-8.9). Wraps a single `RecordsTable` with a "Previous Category" `extraColumns` entry that displays each record's `previous_category` field (null or empty renders as "—"). Display only — records in this bucket require manual administrator review before they can be synced. Exports `MANUAL_UPDATE_STATUS = 'manual_update'` and `PREVIOUS_CATEGORY_COLUMN_LABEL` constants for test assertions. Props: `records` (all manual_update staged records).
- **DiscardPanel component** (`resources/js/components/upload/DiscardPanel.js`): Renders the "Discard" PanelBody content (AORM-8.10). Identical in structure to `ManualUpdatePanel` — wraps a single `RecordsTable` with a "Previous Category" `extraColumns` entry displaying each record's `previous_category` field (null or empty renders as "—"). Display only — per-row and bulk reinstate/remove actions are deferred to AORM-8B.7. Exports `DISCARD_STATUS = 'discard'` and `PREVIOUS_CATEGORY_COLUMN_LABEL` constants for test assertions. Props: `records` (all discard staged records).
- **Staged records table**: A single `wp_wicket_aorm_staged_records` DB table (with `upload_session_id`) serves as the intermediary between file parsing and MDP sync. React state alone was ruled out due to MDP API call volume at scale (1,000+ rows).
- **Bulk action modes**: "Add to roster" vs "Replace roster" — replace mode end-dates existing org relationships before syncing, preserving data integrity.

## Build, Test, and Development Commands

- `composer install`: Install PHP dependencies.
- `npm install`: Install frontend toolchain.
- `npm run build`: Compile React (`resources/js/` → `build/`).
- `npm run start`: Watch mode for React development.
- `composer lint`: PHP style check (php-cs-fixer --dry-run --diff).
- `composer format`: Apply PHP formatting.
- `wicket test unit:admin-org-roster`: Run unit tests with Wicket CLI tool.

## Coding Standards & Formatting

### PHP

- **Standard**: PSR-12 + PER-CS + PHP 8.2 Migration — enforced by `php-cs-fixer v3`.
- **Config file**: `.php-cs-fixer.dist.php` — reuse the shared Wicket ecosystem config (same as `wicket-wp-base-plugin` and `wicket-lib-org-roster`).
- **Key rules enforced by the formatter**:
  - `declare(strict_types=1);` in every PHP file.
  - Short array syntax (`[]` not `array()`).
  - Single quotes for strings.
  - Ordered imports (alphabetical).
  - Trailing commas in multiline arrays/arguments.
  - No unused imports.
  - `elseif` (not `else if`).
  - Visibility required on all methods and properties.
  - One blank line before `return` statements.
  - PHPDoc cleanup: no `@access`, no `@package`, trim whitespace.
- **Commands**:
  - `composer lint` — dry-run check (CI-safe).
  - `composer format` — auto-fix in place.
- **Run `composer lint` before every commit.** CI will reject non-conforming code.

### JavaScript / React

- **Toolchain**: `@wordpress/scripts` — provides ESLint, Prettier, and webpack out of the box.
- **ESLint config**: Extends `@wordpress/eslint-plugin` (included with `@wordpress/scripts`). No custom `.eslintrc` needed unless overriding specific rules.
- **Prettier config**: Follows WordPress defaults (tabs for indentation, single quotes, trailing commas).
- **Commands**:
  - `npm run lint:js` — ESLint check.
  - `npm run format` — Prettier auto-fix.
- **Additional rules**:
  - Use `import` / `export` (ES modules), not `require`.
  - Destructure `@wordpress/components` imports: `import { Button, TextControl } from '@wordpress/components';`
  - Prefer `@wordpress/api-fetch` over raw `fetch()` for REST calls (handles nonce automatically).
  - No `console.log` in committed code (use `console.error` for genuine error paths only).

### Naming Conventions

| Context | Convention | Example |
|---|---|---|
| PHP classes | PascalCase | `StagedRecordsTable` |
| PHP methods/properties | camelCase | `parseUploadedFile()` |
| PHP namespace | `WicketAORM\` | `WicketAORM\Services\FileParserService` |
| React components | PascalCase file

## REST API Namespace

All endpoints register under `wicket-aorm/v1/`. Example routes:

- `GET  /wicket-aorm/v1/rosters` — list org rosters
- `GET  /wicket-aorm/v1/rosters/{org_uuid}` — single roster detail
- `POST /wicket-aorm/v1/upload` — file upload + parse to staged records
- `GET  /wicket-aorm/v1/staged-records/{session_id}` — review staged rows
- `POST /wicket-aorm/v1/sync/{session_id}` — sync staged records to MDP

## Testing Guidelines

- **Tests live in `/qa`** — not inside this plugin directory. No `tests/` folder here.
- Frameworks: Pest + PHPUnit + Brain Monkey (matching the Wicket ecosystem).
- Test files end with `Test.php`.
- Add/update tests for any behavior change, especially REST handlers, services, and database operations.
- React component tests use `@testing-library/react` if applicable.

## Security & WordPress Requirements

- Sanitize all input: `sanitize_text_field()`, `absint()`, `sanitize_file_name()`, etc.
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`.
- Enforce capability checks (`current_user_can()`) on every REST endpoint and admin page.
- Use nonces for all form submissions and REST requests (`wp_create_nonce()` / `wp_verify_nonce()`).
- Use `$wpdb` prepared statements for all direct database queries.
- File uploads: validate MIME type, enforce allowed extension (CSV only), use `wp_handle_upload()`.

## Dependencies & Integrations

- **wicket-wp-base-plugin**: Provides `wicket_api_client()`, `Wicket()` singleton, and MDP connection config. This plugin requires it to be active.
- **wicket-lib-org-roster**: Direct runtime dependency (add to `composer.json`). The following services are consumed directly — do not reimplement this logic:
  - `OrgManagement\Services\PersonService::createOrGetPerson()` — used in `SyncService` to find or create a person in MDP by email before syncing.
  - `OrgManagement\Services\ConnectionService::ensurePersonConnection()` — used in `SyncService` to create a person-to-org relationship (AORM-9.6).
  - `OrgManagement\Services\ConnectionService::endRelationshipToday()` and `endActivePersonOrganizationConnections()` — used in `SyncService` for Replace mode and relationship removal (AORM-9.9, AORM-9.15).
  - CSV header-matching pattern (`getBulkColumnDefinitions()` + `resolveHeaderIndex()`) from `BulkMemberUploadService` — ported into `FileParserService` for flexible column aliasing (AORM-6.10).
  - The lib's configuration is injected via the `wicket/acc/orgman/config` WordPress filter (registered in the active child theme). Services that call `OrgManConfig::get()` internally will pick up that config automatically.
- **MDP API**: All person/org/relationship mutations go through the Wicket API via `wicket_api_client()`.

## Commit & Pull Request Guidelines

- Short, imperative, scope-specific commit messages (e.g., `adds staged records table migration`, `fixes upload validation for CSV headers`).
- Keep commits focused — no mixed refactor/feature changes.
- PRs should include: purpose, risk notes, test evidence, and screenshots for UI changes.
- Link the relevant AORM ticket.