# AORM — Admin Organization Roster Management

## Product overview

The Admin Organization Roster Management (AORM) plugin is a WordPress plugin within the Wicket MDP (Member Data Platform) ecosystem. It provides administrators with tools to manage organization membership rosters at scale, including bulk uploads, validation workflows, duplicate detection, and record synchronization with the MDP.

The plugin slug is `aorm` (not `orm`) because a separate frontend plugin named `orm` already exists in the ecosystem. All namespaces, endpoints, table names, and identifiers use the `aorm` prefix to avoid collisions.

## Core objective

Enable WordPress admins to manage person-to-organization relationships and membership assignments in bulk, with validation and duplicate detection safeguards that prevent bad data from reaching the MDP.

## Key concepts

### Primary entity: org membership

The primary entity in AORM is the **organization membership**, not the organization itself. A single organization can have multiple membership tiers (e.g., "Gold Tier" and "Silver Tier"), and each appears as a separate row in the roster list. Everything — uploads, blocking, replace mode, syncing — is scoped to a specific `org_uuid` + `membership_uuid` pair.

Organization memberships are fetched from the MDP via the `/organizations/<ORG_UUID>/memberships` endpoint. Only memberships with **Active**, **Delayed**, or **Grace Period** status are shown.

### Two roster types

The tool supports two roster types, configured in settings:

1. **Relationship** — manages person↔org relationships for the roster org. Uses the MDP relationship type defined in settings. On sync, creates/ends relationships with start/end dates. Ending a person's relationship to one org may also end their active default-type relationships to other orgs (cross-org side effect).

2. **Direct Assignment** — manages org membership assignments for the roster org. On sync, creates/ends membership assignments with start/end date + timestamp. No cross-org side effects.

### Two bulk actions

When uploading a roster, the admin selects one of two actions:

1. **Add to Roster** — creates additional entries. Does not touch existing roster members who aren't in the file. If an uploaded record matches a person already on the roster, they are flagged as "Already on Roster" and their data (title, etc.) can be updated.

2. **Replace Existing Roster** — compares the upload against the current roster. Adds new members, updates existing ones, and identifies members on the current roster who are NOT in the upload as candidates for removal. Removal means end-dating the relationship/assignment, not deleting it. Replace mode is only available if at least one seat is already assigned.

---

## User roles and access

The tool is for **WordPress administrators only**. All views and REST endpoints are gated behind the `manage_options` capability. Non-admin users cannot see the AORM menu or access any endpoint (403 returned).

---

## Feature details

### Settings & configuration

As an implementation specialist, I can configure the roster management tool.

Settings are managed via the native **WordPress Settings API** (`register_setting`, `add_settings_section`, `add_settings_field`). All settings are stored in `wp_options`.

**Sections:**

1. **Roster Type** — dropdown: Relationship or Direct Assignment. Determines which MDP sync path is used.

2. **Relationship Configuration** (shown only when type = Relationship) — dropdown of person/org relationship types from the connected MDP environment. The selected type is what the roster tool adds/removes/edits.

3. **Security Roles** — multi-select of security roles from the connected MDP environment. When roster edits occur (add/remove), these security roles are applied or removed, scoped to the roster org. Available roles include Org Editor and Membership Manager.

4. **Default Email Type** — dropdown of email types from the MDP. When a new person record is created or an email is added via the roster, this type is assigned.

5. **Default Phone Type** — dropdown of phone types from the MDP. When a new person record is created with a phone number, this type is assigned.

6. **CSV Template & Sample** — documentation of expected CSV columns and a downloadable sample template.

7. **Background Job Settings** — batch size for matching/sync jobs (default 50) and staged records cleanup TTL (default 30 days).

8. **Sync Merge Logic** *(nice to have)* — for each field in the roster import template, admin chooses which value wins when merging: imported value or original value. Fields: First Name, Last Name, Email, Mobile Phone, Title. Defaults to imported value.

### Organization memberships list view

As an admin, I can view a list of organizations with membership rosters available to manage.

The list includes any current organization membership in the MDP (Active, Delayed, or Grace Period status). There may be multiple entries per organization (one per membership tier).

**Implementation:** PHP `WP_List_Table` subclass — no React needed.

**Columns:**
- Organization Name (links to org roster detail view)
- Membership Tier (with membership end date)
- Number of assigned people (assigned / max seats based on max assignment)
- Membership Status
- Created (date)
- Roster Status (derived from staged records: In Progress, Syncing, Failed, etc.)
- Last Updated (date of most recent save in roster tool + user email)
- Link to MDP (links to membership assignment view in MDP)

**Search by:** Organization Name, Organization ID, Organization UUID.

**Filter by:** Roster Status, Membership Tier, Membership Status.

**Sorting:** All columns sortable. Default order is Last Updated date (newest first).

**Pagination:** Built-in WP_List_Table pagination.

### Org roster detail view

As an admin, I can view the details of each roster and access key actions.

When clicking into an org membership from the list, the detail view is rendered as a **React app** (using `@wordpress/components` for native WP admin look and feel), reading `org_uuid` and `membership_uuid` from URL params.

**Heading block** (always visible above tabs):
- Organization Name
- Org ID
- Organization Type (core profile)
- Membership Tier
- Membership Owner
- Current roster count (assigned / max assignment)
- Link to MDP Membership Record

**Three tabs:**

#### Tab 1: Roster Assignment

Table displaying the current roster. Allows management of current roster entries. If no assignments exist, an empty state directs the admin to the Roster Upload tab.

**Columns:** name, email, relationship/assignment type, roles, status.

**Bulk actions** (applied to selected rows via checkboxes):
- Remove person(s) from roster — also removes configured security roles scoped to roster org
- Add role(s) to selected persons — Org Editor and/or Membership Manager
- Remove role(s) from selected persons — Org Editor and/or Membership Manager

**Per-row action:**
- "Edit Permissions" link → opens a Modal with two CheckboxControls: Org Editor and Membership Manager. Admin can toggle either on/off for that specific person.

#### Tab 2: Roster Upload

Core function for adding or replacing rosters. Contains both the individual add form and the bulk upload wizard (multi-step flow).

#### Tab 3: Roster Activity *(nice to have)*

Audit trail of activities related to the roster. Read-only. Each entry includes: date/time, actor name, actor type (admin or system), activity type, summary, status. Filterable by activity type, date range, actor, status. Expandable detail per entry. Paginated or lazy-loaded.

### Individual member add

As an admin, I can add a single member to the roster using a form.

The form is on the Roster Upload tab with fields: first_name, last_name, email (required), mobile_phone, title (optional).

Upon submission, the record goes through the **same validation and MDP matching pipeline as CSV uploads** — there is no instant MDP sync. The record is validated, matched against the MDP, categorized, and appears in the validation view.

Admins can add multiple records this way. The roster has an "In Progress" status until all records are synced or discarded.

**Blocked if an active upload session exists** for this membership. One active session per membership enforced.

### Bulk upload

As an admin, I can bulk upload a list of people to add to a roster. The upload follows a three-step process.

#### Step 1: Upload file

- Drag-and-drop or file picker
- CSV only, 1MB max file size
- Downloadable empty roster template available
- If an active upload session exists for this membership, upload is blocked with option to cancel the existing session

#### Step 2: Select action

Shown after file upload:
- **Add to Roster** — creates additional entries
- **Replace Existing Roster** — only available if at least one seat is already assigned

#### Step 3: Upload validation (local, no MDP calls)

Validates submitted import data before duplicate detection. Each row is checked against validation rules.

**CSV template columns:**
- Required: first_name, last_name, email
- Optional: mobile_phone, title

**Validation rules:**

1. **Required fields** — rows missing first_name, last_name, or email are flagged as "Invalid – Missing Required Data"

2. **Email format** — must contain one `@`, must contain a domain with at least one `.`, must not contain spaces, must not begin or end with punctuation, must not exceed reasonable length limits. Flagged as "Invalid – Email Format"

3. **Mobile phone format** — non-numeric characters ignored during validation, resulting number must match valid length per MDP rules. If invalid: "Invalid – Phone Format". Empty is valid.

4. **Duplicate detection within import** — rows with identical first_name + last_name + email are flagged as "Duplicate in Import File"

**Validation output per row:** Valid, Invalid (with reason), or Duplicate.

**If ANY rows are invalid or duplicate**, the "Proceed" button is disabled. Admin must fix the CSV and re-upload. The "Re-upload" button clears the current session and returns to Step 1. Only when ALL rows are valid can the admin proceed to MDP matching.

### Background MDP matching

After file validation passes, a background job processes all valid rows against the MDP.

**Scoring logic (draft — subject to refinement):**

| Match scenario | Score | Category |
|---|---|---|
| Email exact + first name + last name | 100 | Exact Match → Ready to Sync |
| Email exact + name differs | 80 | Probable Match |
| First + last name exact, different email | 60 | Probable Match |
| Email domain + last name match | 40 | Possible Match |
| Last name + first name partial | 30 | Possible Match |
| Last name only | 20 | Possible Match |
| No matches | 0 | Ready to Sync (New Record) |

**Thresholds:** 80+ = Probable Match, 20–79 = Possible Match, 0 = New Record.

**Additional checks:**
- If matched person is already on this roster → status "Already on Roster" in Ready to Sync
- For Replace mode: current roster is fetched from MDP and compared against uploaded records. Current members not in the upload are added to Ready to Sync with status "Remove Existing Record"

Processing runs in batches (configurable, default 50) via Action Scheduler. React polls a progress endpoint.

### Upload validation view

After MDP matching completes, the admin reviews imported records categorized into 5 accordion groups. Admins can move records between categories by executing actions.

**Categories:**

#### Ready to Sync

Records safe to sync, either through validation or admin action.

**Template header:** import file name, selected action (Add / Replace).

**Record statuses:**
- **New Record** — identified as new to the MDP. Will create a new person on sync.
- **Exact Record Match** — exact match to existing MDP record. Will use existing record on sync.
- **Merging to Record** — admin identified as duplicate. Will merge roster details to existing record on sync.
- **Already on Roster** — person already on this org's roster. Will update data (title, etc.) on sync.
- **Remove Existing Record** — (Replace mode only) on current roster but not in upload. Will be end-dated on sync.

**Two tables:**

1. **Records being added** — columns: first_name, last_name, email, title, mobile_phone, Status. Per-row actions: Discard (all), See Details (Merging to Record — opens modal), View in MDP (Exact Match, Already on Roster). Bulk actions: Sync to MDP, Discard.

2. **Records being removed** — only shown in Replace mode. Lists current roster members not in the upload. All rows have status "Remove Existing Record". Per-row actions: View in MDP, Discard Removal (keeps person on roster).

**Sync action:** admin can sync all records in the category, or selected rows only. Partial sync is supported — admin can sync some rows now, return later for the rest. The session stays active until all records are either synced or discarded.

#### Possible Match / Probable Match

Records with potential MDP matches that need admin review.

Extra column: # of matches (total potential matches found in MDP).
Per-row action: View Match → opens Review Match modal.

#### Manual Updates

Functionally same as Discard but with a different label to support the admin workflow. For records the admin wants to handle outside the tool.

Shows Previous Category column. Actions (per-row and bulk): Reinstate (returns to previous category), Remove (permanently deletes from session).

#### Discard

Records that will not be synced.

Shows Previous Category column. Actions (per-row and bulk): Reinstate (returns to previous category), Remove (permanently deletes from session).

**Table features (all accordions):**
- Sortable by first_name, last_name, email
- Searchable by first_name, last_name, full name, email (search works across all pages)
- 10 rows per page, paginated

### Review Match modal

Triggered by clicking "View Match" from Possible Match or Probable Match rows.

**Section 1: New record details** — full name, email, mobile phone, title.

**Section 2: Possible matches table** (from MDP) — columns:
- User: Full Name (ID)
- Emails: primary first, additional comma-separated
- Location: city, state/province (primary address)
- Phone: mobile number
- Title: from person profile
- Employer: org name of each unique org with "Employee" relationship type (lazy-loaded on modal open)
- Membership Status: Active / Inactive
- Link to MDP: external link to person profile

Values that triggered the match are highlighted in the table.

**Actions (radio buttons):**
- **Create as New Record** → moves to Ready to Sync with "New Record" status
- **Manual Updates** → moves to Manual Updates category
- **Merge to Existing Record** → admin selects which match to merge with. Merge summary shows final post-sync values with overwritten fields highlighted as "merged"
- **Discard** → moves to Discard category

### Sync logic

Sync cannot be cancelled once started. Admin must confirm via Modal before proceeding.

Partial sync is supported: admin can sync selected rows or all rows at once. Session stays active until everything is synced or discarded.

Sync branches on roster type setting.

#### Relationship sync

**New Record:**
1. Create person in MDP (first name, last name, email with type from settings, title, phone with type from settings)
2. Create relationship (to roster org, default type from config, start date = today)
3. Apply "user" role + configured security roles scoped to roster org

**Exact Match / Already on Roster:**
1. Update title from import
2. If active default-type relationship to another org → end date it (today)
3. If active default-type relationship to roster org → no change
4. If no active default-type relationship to roster org → create it
5. Ensure "user" role + configured security roles

**Merging to Record:**
1. Use imported first name, last name, title
2. Add imported email as primary, keep existing emails as non-primary
3. Keep existing phone
4. Add relationship to roster org, end default-type relationships to other orgs
5. Apply configured security roles, keep existing

**Remove (Replace mode only):**
1. End date the default relationship to roster org (today)
2. Revoke configured security roles scoped to roster org

**Cross-org warning:** when syncing Exact Match or Merge records, if the person has active default-type relationships to other orgs that will be ended, the admin is warned before sync.

#### Direct assignment sync

**New Record:**
1. Create person in MDP (same fields)
2. Create membership assignment (roster org, start = today + timestamp)
3. Apply "user" role + configured security roles

**Exact Match / Already on Roster:**
1. Update title
2. If no active assignment to roster org → create it
3. Ensure roles

**Merging to Record:**
1. Same field updates as relationship mode
2. Add assignment if missing
3. Apply roles

**Remove (Replace mode only):**
1. Set assignment end date = today + timestamp
2. Revoke configured security roles scoped to roster org

No cross-org side effects in direct assignment mode.

### Roster activity / logs

**Roster Activity tab** *(nice to have)*: scoped audit trail within the roster detail view for a specific org membership.

**Global Logs page**: dedicated admin subpage showing all ORM activity across all rosters. Uses `WP_List_Table` (PHP).

**Activity entry fields:** date/time, actor name, actor type (admin/system), activity type (upload, validation, matching, sync, member_add, member_remove, role_change), summary, status (success/failed/in_progress).

**Filters:** organization, membership tier, activity type, date range, actor, status.

**Read-only** — entries cannot be edited or deleted through the UI. Expandable detail per entry.

---

## Technical architecture

### Plugin structure

- **Plugin directory:** `wicket-admin-org-roster`
- **Project slug:** `aorm`
- **Namespace:** `WicketAORM\`
- **PSR-4 autoloading** via Composer under `src/`
- **REST endpoints** under `wicket/v1/aorm/`
- **React islands** compiled via `@wordpress/scripts` into `build/`
- **Tests** in `/qa/tests/Unit/AdminOrgRoster` (separate `/qa` folder, not inside plugin directory)

### Frontend split

| Screen | Rendering | Why |
|---|---|---|
| Org memberships list | PHP `WP_List_Table` | Standard read-only list with search/filter/sort/pagination — WordPress handles natively |
| Global logs page | PHP `WP_List_Table` | Same pattern — read-only list |
| Settings page | PHP (WP Settings API) | Standard WordPress settings — `register_setting`, `add_settings_section`, `add_settings_field` |
| Roster detail view (tabs) | React + `@wordpress/components` | Complex stateful UI — tabs, modals, multi-step wizard, accordion categories |
| Upload wizard | React + `@wordpress/components` | Multi-step flow with file upload, validation, progress tracking |
| Validation view | React + `@wordpress/components` | 5 accordion categories, drag between categories, Review Match modal, merge preview |
| Sync progress | React + `@wordpress/components` | Real-time progress polling, results display |

### WordPress component mapping

| `@wordpress/components` | Used in |
|---|---|
| `TabPanel` | Roster detail view tabs |
| `Modal` | Edit Permissions, Review Match, sync confirmation |
| `Button`, `ButtonGroup` | Actions everywhere |
| `TextControl` | Individual add form, settings |
| `SelectControl` | Action selector, settings dropdowns |
| `CheckboxControl` | Edit Permissions, row selection |
| `RadioControl` | Review Match actions, merge target selection |
| `Notice` | Error/success messages |
| `Spinner` | Loading states, matching progress |
| `SearchControl` | Table search within accordions |
| `Panel`, `PanelBody` | Accordion categories in validation view |
| `DropZone`, `FormFileUpload` | File upload |
| `DropdownMenu` | Bulk action dropdowns |

WordPress dependencies (`wp-components`, `wp-element`, `wp-api-fetch`) are declared as script dependencies, not bundled.

### Database schema

Three custom tables created on plugin activation via `dbDelta`.

#### wp_wicket_aorm_staged_records

The workhorse table tracking every imported record through the full lifecycle.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT, PK, AI | Primary key |
| upload_session_id | VARCHAR(36) | Groups rows per upload |
| action_type | ENUM(add, replace) | Bulk action chosen |
| org_uuid | VARCHAR(36) | Target organization |
| membership_uuid | VARCHAR(36) | Specific org membership |
| raw_data | LONGTEXT (JSON) | Original row from file |
| validation_status | ENUM(valid, invalid, duplicate) | Local validation result |
| validation_message | VARCHAR(255), NULL | Reason if invalid |
| category | ENUM | ready_to_sync, possible_match, probable_match, manual_update, discard |
| previous_category | ENUM, NULL | For reinstate from discard/manual |
| record_status | ENUM | new_record, exact_match, merging_to_record, already_on_roster, remove_existing |
| match_count | INT, DEFAULT 0 | MDP matches found |
| matched_persons | LONGTEXT (JSON) | Array of matched person UUIDs + scores |
| match_details | LONGTEXT (JSON) | Fields that triggered each match |
| merge_target_uuid | VARCHAR(36), NULL | Person selected for merge |
| merge_preview | LONGTEXT (JSON) | Computed final field values |
| sync_status | ENUM | pending, ready_to_sync, synced, failed |
| error_details | TEXT, NULL | Error info if sync failed |
| uploaded_by | BIGINT | WP user ID |
| created_at | DATETIME | Row insert timestamp |
| updated_at | DATETIME | Last status change |

**Indexes:** upload_session_id, org_uuid, membership_uuid, sync_status, category.

#### wp_wicket_aorm_logs

Audit trail for all ORM activity.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT, PK, AI | Primary key |
| org_uuid | VARCHAR(36) | Organization |
| membership_uuid | VARCHAR(36), NULL | Specific membership |
| upload_session_id | VARCHAR(36), NULL | Links to upload session |
| actor_type | ENUM(admin, system) | Who performed action |
| activity_type | ENUM | upload, validation, matching, sync, member_add, member_remove, role_change |
| summary | VARCHAR(255) | Short scannable description |
| details | LONGTEXT (JSON) | Expandable metadata |
| status | ENUM, NULL | success, failed, in_progress |
| created_by | BIGINT, NULL | WP user ID (null if system) |
| created_at | DATETIME | When action occurred |

**Indexes:** org_uuid, membership_uuid, created_at, activity_type.

#### wp_wicket_aorm_roster_meta

Persistent per-roster state table. Survives staged records cleanup so the list view always shows the latest known status, who last touched it, and when. Also serves as a fast lookup for the Org Roster List view (single indexed query per row instead of scanning logs or staged records).

| Column | Type | Notes |
|---|---|---|
| id | BIGINT, PK, AI | Primary key |
| org_uuid | VARCHAR(36) | Organization |
| membership_uuid | VARCHAR(36) | Unique per membership |
| roster_status | ENUM, NULL | idle, in_progress, syncing, has_failures, synced |
| last_updated_at | DATETIME | Most recent roster save |
| last_updated_by | VARCHAR(255) | User email |
| last_synced_at | DATETIME, NULL | Timestamp of last successful sync |

**Indexes:** membership_uuid (unique).

**Lifecycle updates:**
- Upload starts / individual add / roster assignment change → `in_progress`, update `last_updated_at` + `last_updated_by`
- Sync running → `syncing`
- Sync completed successfully → `synced`, set `last_synced_at`
- Sync completed with failures → `has_failures`

### REST API endpoints

All endpoints under the `wicket/v1/aorm/` namespace. All require `manage_options` capability.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/rosters` | List org memberships (for WP_List_Table AJAX if needed) |
| GET | `/rosters/{org_uuid}/{membership_uuid}` | Roster detail + org/membership info |
| DELETE | `/rosters/{org_uuid}/{membership_uuid}/members` | Bulk remove members |
| POST | `/rosters/{org_uuid}/{membership_uuid}/roles` | Bulk add/remove roles |
| POST | `/rosters/{org_uuid}/{membership_uuid}/individual` | Add individual member |
| POST | `/uploads` | Upload CSV file |
| DELETE | `/uploads/{id}` | Cancel/clear upload session |
| GET | `/uploads/{id}/status` | Matching/sync progress |
| GET | `/uploads/{id}/staged` | Get staged records by category |
| POST | `/uploads/{id}/commit` | Trigger sync (accepts row IDs or "all") |
| PATCH | `/staged/{id}/category` | Move record between categories |
| PATCH | `/staged/{id}/resolve` | Save admin action (merge target, etc.) |
| DELETE | `/staged/{id}` | Permanently remove record from session |
| GET | `/staged/{id}/matches` | Full match details from MDP (lazy-loaded) |
| GET | `/logs` | Global logs (for WP_List_Table AJAX if needed) |
| GET | `/rosters/{org_uuid}/{membership_uuid}/activity` | Roster-scoped activity |

### Background jobs

Two Action Scheduler jobs:

1. **MDP Matching** — processes staged records in batches (default 50), queries MDP by email then name, scores matches, categorizes records. Re-schedules itself until all pending rows are processed.

2. **MDP Sync** — processes ready_to_sync records in batches, executes the appropriate sync logic (relationship or direct assignment), updates sync_status per row.

Both jobs handle MDP rate limits with retry and backoff.

A scheduled cleanup job removes staged records older than the configured TTL (default 30 days).

### Session enforcement

One active upload session per membership at a time. Both bulk upload and individual add check for existing sessions and block if one exists. The admin can cancel a pending session to start a new one.

### Coding standards

- **PHP:** PHP 8.2+, PSR-12, `php-cs-fixer v3` with `@PSR12` + `@PER-CS` + `@PHP82Migration` rules (shared Wicket ecosystem `.php-cs-fixer.dist.php` config)
- **JS/React:** `@wordpress/scripts` ESLint and Prettier defaults
- **Naming:** classes PascalCase, methods camelCase, AORM prefix on all identifiers
- **Testing:** Pest + PHPUnit + Brain Monkey in `/qa/tests/Unit/AdminOrgRoster`

### Ecosystem context

The plugin lives within the broader Wicket WordPress ecosystem:

- **wicket-wp-base-plugin** — shared base plugin with MDP API helpers (`wicket_api_client()`, `wicket_get_organization()`, `wicket_get_person_by_id()`, etc.), logging (`Wicket()->log()`), and the existing REST class patterns
- **wicket-lib-org-roster** — existing library with similar API calls for org roster operations (reference implementation)
- **ORM plugin (frontend)** — separate frontend plugin (`orm` slug) that handles member-facing roster features. AORM is the admin-facing counterpart.

---

## Open questions

- **Exact thresholds for Possible vs Probable Match** — scoring logic is draft (0–100 scale). Needs refinement based on real-world testing.
- **Duplicate relationship handling** — if a person already has a relationship with the roster org and appears in the CSV, currently treated as "Already on Roster" and data is updated. Confirm this is the desired behavior.

---

## Figma design reference

https://www.figma.com/design/LEYSMctTf2vTt4Qo65EQRm/ORM-Feature--ASAE-?node-id=0-1&t=3TNF24L6lCs4YVG6-1