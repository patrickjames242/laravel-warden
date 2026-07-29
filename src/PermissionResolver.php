<?php

declare(strict_types=1);

namespace Warden;

interface PermissionResolver
{
    /**
     * May return either permission strings or WardenPermission instances (or a
     * mix); the framework normalizes strings via WardenPermission::fromString.
     *
     * @return iterable<int, WardenPermission|string>
     */
    public function resolve(PermissionResolutionContext $context): iterable;
}
