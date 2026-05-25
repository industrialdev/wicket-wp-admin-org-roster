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
- **RecordsTable component** (`resources/js/components/upload/RecordsTable.js`): Reusable table used inside every validation-review accordion panel (AORM-8.3). Provides client-side sorting (first/last/email columns), `SearchControl` filtering across all records, and 10-per-page pagination. Category-specific columns (e.g. `# Matches`, `Previous Category`) are injected via the `extraColumns` prop. Exports `PAGE_SIZE = 10` and `BASE_COLUMNS` constants.
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