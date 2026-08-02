<?php

return [
    /*
     * The permission resolver: a class implementing Warden\PermissionResolver.
     *
     * Warden ships no default — you must provide one. It maps the current user
     * to the WardenRuleSet that governs their access to an entity, built from
     * wherever your permissions live: role/permission tables, JWT claims, a
     * remote service, config, etc.
     */
    'permission_resolver' => null,

    /*
     * The Warden policies registered with the application. Registration is
     * explicit: every policy that governs a resource must be listed here. A
     * policy that is not listed is unknown to permission validation and lookups.
     */
    'policies' => [
        // App\Policies\PostPolicy::class,
    ],
];
