<?php

declare(strict_types=1);

namespace WicketAORM\Database;

/**
 * Schema create/upgrade on activation.
 */
class Migrator
{
    /**
     * Create or update the staged-records database table.
     */
    public function up(): void
    {
        // TODO: Create wp_wicket_orm_staged_records table via dbDelta().
    }
}
