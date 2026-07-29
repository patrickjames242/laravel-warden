<?php

namespace Warden\Facades;

use Illuminate\Support\Facades\Facade;
use Warden\WardenManager;

/**
 * @method static string|array validatePermissionStrings(string|array $permissionStrings)
 * @method static string getPolicyForModelClass(string $modelClass)
 * @method static string getPolicyForPermissionBaseName(string $permissionBaseName)
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$policyClassesOrPermissionBaseNames)
 * @method static array registeredPolicies()
 *
 * @see \Warden\WardenManager
 */
class Warden extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WardenManager::class;
    }
}
