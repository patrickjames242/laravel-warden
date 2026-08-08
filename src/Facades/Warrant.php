<?php

namespace Warrant\Facades;

use Illuminate\Support\Facades\Facade;
use Warrant\WarrantManager;

/**
 * @method static string getSchemaForModelClass(string $modelClass)
 * @method static string getSchemaForKey(string $schemaKey)
 * @method static string resolveSchemaKey(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema)
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$schemaClassesOrSchemaKeys)
 * @method static array registeredSchemas()
 *
 * @see \Warrant\WarrantManager
 */
class Warrant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WarrantManager::class;
    }
}
