<?php

namespace Warden\Facades;

use Illuminate\Support\Facades\Facade;
use Warden\WardenManager;

/**
 * @method static string getSchemaForModelClass(string $modelClass)
 * @method static string getSchemaForKey(string $schemaKey)
 * @method static string resolveSchemaKey(\Illuminate\Database\Eloquent\Model|\Warden\Schema\WardenSchema|string $schema)
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$schemaClassesOrSchemaKeys)
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
