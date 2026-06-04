<?php

declare(strict_types=1);

namespace WicketAORM\Services;

/**
 * Sync staged records to MDP (relationships, persons).
 *
 * The per-record sync logic is implemented ticket-by-ticket in AORM-9.4+.
 * This class is instantiated by SyncJobRunner (AORM-9.3); the syncRecord()
 * entry point is the only public surface called by the runner.
 */
class SyncService
{
    /**
     * Sync a single staged record to MDP.
     *
     * Reads the record's record_status and routes to the appropriate sync
     * path. The branching logic and each sync path are implemented in
     * AORM-9.4 through AORM-9.15.
     *
     * @param array<string, mixed> $record  A row from wp_wicket_aorm_staged_records.
     *
     * @see AORM-9.4  — roster type routing (Phase 1: always relationship path)
     * @see AORM-9.5  — new_record: create person
     * @see AORM-9.6  — new_record: create relationship
     * @see AORM-9.8  — exact_match / already_on_roster: update title
     * @see AORM-9.11 — merging_to_record: update name/title
     * @see AORM-9.15 — remove_existing: end-date relationship
     */
    public function syncRecord(array $record): void
    {
        // Routing and MDP calls implemented in AORM-9.4+.
    }
}
