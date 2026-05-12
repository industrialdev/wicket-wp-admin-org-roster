<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

/**
 * File upload + parse to staged records endpoint.
 *
 * The downloadable CSV template is served as a static file from
 * assets/roster-template.csv (AORM-6.3) — no endpoint needed.
 *
 * Routes:
 *   POST /wicket-aorm/v1/uploads  — upload + parse CSV (AORM-6.5)
 */
class UploadController extends RestController
{
    /**
     * Register routes for file upload.
     */
    public function register_routes(): void
    {
        // TODO: Register POST /uploads route (AORM-6.5).
    }
}
