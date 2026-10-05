# Roster Management (AORM) — Admin Guide

*A plain-language guide to the Roster Management tool for administrators, org managers, and implementation specialists.*

## What this tool does

Roster Management (internally called AORM, short for "Admin Organization Roster Management") is a WordPress admin tool for managing who belongs to an organization's membership roster in the Wicket Member Data Platform (MDP).

Instead of adding people one at a time, an admin can upload a spreadsheet of names, emails, and titles, and the tool will:

- Check the file for obvious problems (missing names, bad emails, duplicate rows).
- Compare every person against existing records in the MDP to avoid creating duplicate people.
- Let an admin review and resolve anything uncertain (possible matches, near-matches, records that need a manual look).
- Apply the changes to the MDP once the admin confirms — creating new people where needed, linking them to the organization, and applying the right roles.

It also supports adding a single person at a time, removing people from a roster, and reviewing a history of everything that's happened on a roster.

## Who can use it

Roster Management is restricted to WordPress users with administrator-level access (the `manage_options` capability). Anyone without that access won't see the "Roster Management" menu item at all, and the underlying tool will refuse any request from them.

## Where to find it

A "Roster Management" item appears in the WordPress admin sidebar, with these pages underneath it:

- **Organization Rosters** — the main list of every organization and membership tier that has a roster to manage. This is the home page of the tool.
- **Group Rosters** — reserved for a future feature (rosters for member groups rather than organizations). It currently shows a "coming soon" placeholder and isn't usable yet.
- **Settings** — configuration for how syncing and roles behave (details below).
- **Logs** — a searchable history of every roster action taken across the whole site.

## Getting started

For a first-time setup, do these in order:

1. **Confirm you have access.** You'll need a WordPress administrator account. If "Roster Management" doesn't appear in your sidebar, you don't have the required access — ask a site admin to grant it.
2. **Check Settings before your first upload.** Go to **Roster Management → Settings** and confirm:
   - **Roster type** matches how your organization actually manages membership (Relationship or Direct Assignment) — this decides how every sync behaves, so it's worth getting right up front.
   - **Base member role** and **Additional security roles** are set to whatever roles new roster members should receive.
   - **Default email type** and **Default phone type** match your org's convention.
   - Leave **Sync batch size** and **Staged record retention** at their defaults (50 and 30 days) unless you have a specific reason to change them.
3. **Find the organization you want to manage.** Go to **Roster Management → Organization Rosters** and search or browse to the organization and membership tier you're working on.
4. **Try a small test upload first.** Click into that organization, open the **Roster Upload** tab, and upload a short CSV (a handful of rows) using **Add to Roster**. Walk through the review screen and confirm the results look right in the MDP before running a full roster through the tool.
5. **Once comfortable, do the real upload.** Use **Replace Existing Roster** instead of **Add to Roster** if the file represents the organization's complete, current membership list and you want anyone missing from it removed.

From here, the sections below cover each screen and workflow in detail.

## Key concept: a "roster" is a membership tier, not just an organization

A single organization can have more than one membership tier — for example, a "Gold" tier and a "Silver" tier. Each tier is its own roster with its own list of people, and shows up as its own row on the Organization Rosters page. Everything in this tool — uploads, review, syncing — happens against one specific organization + membership tier combination at a time.

Only memberships that are currently Active, Delayed, or in a Grace Period are shown; expired or cancelled memberships don't appear as manageable rosters.

## The Organization Rosters list

This page lists every manageable roster with the following information:

- **Organization Name** — click through to manage that roster.
- **Membership Tier**
- **# Assigned** — how many people currently hold this membership.
- **Membership Status**
- **Created** — when the membership record was created.
- **Updated At** — when the membership record was last changed in the MDP.
- **Roster Status** — a quick read on where things stand (idle, in progress, syncing, has failures, or fully synced).
- **Roster Last Saved** — the last time someone made a change in this tool, and who did it.
- **MDP** — a direct link to view the record in the MDP.

You can search by organization name, ID, or UUID, sort by most columns, and filter to "Cascadeable Only" or "Non-Cascadeable Only" organizations using the dropdown above the table.

## Managing a single roster

Clicking into an organization from the list opens its roster detail page, which is organized into three tabs.

### Tab 1: Roster Assignment

Shows everyone currently on this roster: name, email, relationship or assignment type, roles, and status. The membership owner is always pinned to the top of the list with an "Owner" badge.

From here an admin can:

- Search the list by name or email.
- Select one or more people and, in bulk, **remove them from the roster**, **add a role** to them, or **remove a role** from them (currently just Membership Manager).
- Click "Edit Permissions" on an individual row to toggle their roles one at a time.

Removing someone asks for confirmation first, since it also strips their configured roles for this organization.

### Tab 2: Roster Upload

This is where new people get added to the roster, either one at a time or in bulk. See "Adding people to a roster" below for the full walkthrough.

### Tab 3: Roster Activity

A read-only history of everything that's happened on this specific roster — uploads, syncs, member additions/removals, role changes — with date/time, who or what did it (an admin or a background system process), a short summary, and a status. Entries can be expanded for more detail.

## Adding people to a roster

There are two ways to add people, and both go through the same review process before anything is actually saved to the MDP.

### Adding one person

A simple form (first name, last name, email required; phone and title optional) on the Roster Upload tab. Submitting it doesn't create anything in the MDP immediately — the record goes through the same checks as a bulk upload, so it will show up in the review screen described below.

### Uploading a spreadsheet

The bulk upload is a guided, multi-step process:

**Step 1 — Upload the file.** Drag and drop a CSV file (1 MB max), or use the file picker. A blank template is available to download so the columns line up correctly. Only one upload can be in progress for a given roster at a time — if one is already active, the admin is prompted to cancel it before starting a new one.

**Step 2 — Choose what the upload should do:**
- **Add to Roster** — adds the people in the file without touching anyone already on the roster who isn't in the file.
- **Replace Existing Roster** — compares the file against who's currently on the roster. New people are added, existing people are updated, and anyone currently on the roster who is *not* in the file is flagged for removal. (Only available once at least one person is already on the roster.) "Removal" here means their membership is end-dated, not that the person record itself is deleted.

**Step 3 — File validation.** Every row is checked locally, before anything is sent to the MDP:
- First name, last name, and email are required.
- Names must contain only letters, spaces, hyphens, and apostrophes.
- Emails must be properly formatted.
- Phone numbers, if provided, must have a reasonable number of digits (empty is fine).
- Rows with the same email address as an earlier row in the same file are flagged as duplicates.

If anything fails validation, the admin can't proceed — they need to fix the file and re-upload. Only a fully clean file moves on to the next step.

**Step 4 — Matching against the MDP.** A background job compares every valid row against existing MDP records (by email and name) to figure out whether each person is brand new, an exact match to someone who already exists, or something in between. This runs in the background in batches, so large files don't time anything out; a progress bar shows how far along it is.

**Step 5 — Review.** Once matching finishes, every record lands in one of five buckets for the admin to review:

- **Ready to Sync** — safe to send to the MDP as-is. This includes brand-new people, exact matches to an existing record, people already on this roster (just getting their info refreshed), and anyone the admin has explicitly resolved from another bucket. If "Replace Existing Roster" was chosen, this bucket also lists the people being removed.
- **Possible Match** / **Probable Match** — the file's record looks similar to one or more existing MDP records, but not similar enough to auto-resolve. The admin opens a "Review Match" window, compares the imported details side-by-side with the candidate matches (name, email, phone, employer, membership status, etc. — with the fields that triggered the match highlighted), and decides: treat as a brand-new person, merge into one of the existing matches, send to Manual Updates, or discard.
- **Manual Updates** — records the admin wants to leave alone for now and possibly handle outside the tool. They can be sent back to their original bucket ("Reinstate") or removed from the session permanently.
- **Discard** — records that won't be synced. Same Reinstate/Remove options as Manual Updates.

Records can be searched, sorted, and moved between buckets at any point before syncing. Nothing touches the MDP until the admin explicitly starts a sync.

**Step 6 — Sync.** The admin confirms via a pop-up (sync can't be undone once started), and can sync everything at once or just a selection — so a large roster can be synced in parts, with the rest returning to the review screen for later. A progress screen shows how many records have synced successfully and lists any that failed, along with the reason, and offers a one-click retry for just the failed ones.

## What happens when a record syncs

Behind the scenes, syncing does different things depending on the record's status and the "Roster Type" chosen in Settings (see below), but at a high level:

- **New people** are created in the MDP, linked to the organization, and given the configured roles.
- **People who already exist** get their title/contact info refreshed and are linked to the organization if they aren't already.
- **Merged records** combine the imported details with an existing MDP person, with the imported email set as their primary address.
- **Removed people** (Replace mode only) have their membership or relationship end-dated, and their configured roles for that organization are revoked. Their MDP person record itself is never deleted.

One setting-driven detail worth knowing: if the roster is configured as "Relationship" type (see Settings), adding or merging a person to this organization can automatically end their active relationship to any *other* organization — this is called out with an on-screen warning before you sync, and specific relationship types (like admin roles) can be protected from being ended automatically.

## Activity Logs (site-wide)

The Logs page shows a history of roster activity across every organization, not just one. Each entry shows the date/time, organization, membership tier, who or what performed the action, the type of activity, a summary, and a status, with the full detail available by expanding a row. It can be filtered by organization, membership tier, activity type, status, and date range. This is a read-only audit trail — nothing here can be edited or deleted.

## Settings

The Settings page controls how syncing behaves. Changes here only affect future syncs — they don't retroactively change anyone already on a roster.

| Setting | What it controls |
|---|---|
| **Roster type** | Whether this site manages membership through person-to-organization *relationships*, or through direct *membership assignments*. This determines which sync logic runs. |
| **Base member role** | An MDP role automatically applied to everyone synced to any roster (e.g. "member"). Leave blank to apply none. |
| **Protected relationship types** | Relationship types (e.g. "admin") that should never be automatically ended when a person's relationship to another organization is superseded by joining this roster. |
| **Additional security roles** | Extra MDP roles (beyond the base member role) applied to everyone synced — for example, giving roster members "Membership Manager" access. |
| **Default email type** | Which email type (work, home, personal, etc.) is used when a new email address is created for someone during a sync. |
| **Default phone type** | Same idea, for phone numbers. |
| **Sync batch size** | How many records the background job processes per run. Higher = faster syncing, but more load on the MDP at once. Defaults to 50. |
| **Staged record retention (days)** | How many days completed upload records are kept before being automatically cleaned up. Defaults to 30. |

## Not yet available

**Group Rosters** is visible in the menu but is a placeholder for future work — there's nothing to manage there yet.

---

*This guide reflects the tool as currently built. For a deep technical reference (database structure, code architecture, REST endpoints), see `AGENTS.md` in the plugin's source repository — that's written for developers, not day-to-day users.*
