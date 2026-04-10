<?php

declare(strict_types=1);

namespace WicketAORM\Rest;

/**
 * Base REST controller — shared namespace and permission helpers.
 */
abstract class RestController extends \WP_REST_Controller
{
    /**
     * REST API namespace.
     */
    protected $namespace = 'wicket-aorm/v1';

    /**
     * Default permission check — requires manage_options capability.
     */
    public function get_items_permissions_check($request): bool
    {
        return current_user_can('manage_options');
    }
}
