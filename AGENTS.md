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
│   │   ├── RestController.php     # Base controller (namespace, permissions)
│   │   ├── RosterController.php   # Roster list/detail endpoints
│   │   ├── UploadController.php   # File upload + parse → staged records
│   │   ├── StagedRecordController.php
│   │   └── SyncController.php     # MDP sync (create/merge/update)
│   ├── Services/
│   │   ├── FileParserService.php  # CSV → normalized rows
│   │   ├── ValidationService.php  # Row-level validation rules
│   │   ├── MatchingService.php    # Duplicate detection against MDP
│   │   ├── SyncService.php        # Staged → MDP (relationships, persons)
│   │   └── MdpClient.php          # Thin wrapper around wicket_api_client()
│   └── Admin/
│       └── MenuPage.php           # Register admin pages (React mount points)
│
├── resources/js/                  # React source (compiled by @wordpress/scripts)
│   ├── index.js                   # Entry — mounts React islands per admin page
│   ├── pages/                     # Page-level React components
│   ├── components/                # Shared UI built with @wordpress/components
│   ├── hooks/                     # Custom React hooks (useRestApi, useStagedRecords)
│   └── utils/                     # apiFetch wrapper, helpers
│
├── build/                         # Compiled output (gitignored)
└── languages/                     # i18n .pot/.po/.mo
```

## Key Architectural Decisions

- **Staged records table**: A single `wp_wicket_orm_staged_records` DB table (with `upload_session_id`) serves as the intermediary between file parsing and MDP sync. React state alone was ruled out due to MDP API call volume at scale (1,000+ rows).
- **React islands**: Multiple mounted React apps per admin page (in places with difficult layout or data manipulation), not a monolithic SPA — avoids conflicts with WordPress admin navigation. Certain admin page renders a `<div id="aorm-{page}">` mount point in PHP.
- **@wordpress/components**: All React UI must use `@wordpress/components` for consistent WP admin look and feel. Do not introduce Material UI, Chakra, or other component libraries.
- **Bulk action modes**: "Add to roster" vs "Replace roster" — replace mode clears existing org relationships before syncing.

## Build, Test, and Development Commands

- `composer install`: Install PHP dependencies.
- `npm install`: Install frontend toolchain.
- `npm run build`: Compile React (`resources/js/` → `build/`).
- `npm run start`: Watch mode for React development.
- `composer lint`: PHP style check (php-cs-fixer --dry-run --diff).
- `composer format`: Apply PHP formatting.
- `wicket test unit:admin-org-roster`: Run unit tests with Wicket CLI tool.

## Coding Style & Naming Conventions

- PHP 8.2+, `declare(strict_types=1);`, PSR-12.
- PSR-4 namespace: `WicketAORM\` mapped to `src/`.
- Classes: `PascalCase`. Methods/properties: `camelCase`. Test files: `*Test.php`.
- Favor small methods, early returns, and WordPress-native APIs/hooks.
- External API function names (`wicket_api_client()`, `wp_create_nonce()`, etc.) stay as-is.
- React components: PascalCase filenames matching component name. Hooks: `use` prefix, camelCase.

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
- File uploads: validate MIME type, enforce allowed extensions (CSV), use `wp_handle_upload()`.

## Dependencies & Integrations

- **wicket-wp-base-plugin**: Provides `wicket_api_client()`, `Wicket()` singleton, and MDP connection config. This plugin requires it to be active.
- **wicket-lib-org-roster**: Reference for MDP API call patterns (`MemberService`, `OrganizationService`, `ConnectionService`, bulk upload flow). Use similar patterns in `MdpClient` and `SyncService`.
- **MDP API**: All person/org/relationship mutations go through the Wicket API via `wicket_api_client()`.

## Commit & Pull Request Guidelines

- Short, imperative, scope-specific commit messages (e.g., `adds staged records table migration`, `fixes upload validation for CSV headers`).
- Keep commits focused — no mixed refactor/feature changes.
- PRs should include: purpose, risk notes, test evidence, and screenshots for UI changes.
- Link the relevant AORM ticket.