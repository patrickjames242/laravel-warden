<?php

namespace Warden\Facades;

use Illuminate\Support\Facades\Facade;
use Warden\WardenManager;

/**
 * @method static string getSchemaForModelClass(string $modelClass)
 * @method static string getSchemaForPermissionBaseName(string $permissionBaseName)
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$schemaClassesOrPermissionBaseNames)
 * @method static array registeredSchemas()
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
