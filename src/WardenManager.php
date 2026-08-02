<?php

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;
use Warden\Schema\WardenSchema;

/**
 * Central registry and validation entry point for Warden.
 *
 * Responsible for:
 * - mapping model classes to schema classes
 * - mapping permission base names to schema classes
 * - validating persisted permission strings against registered schemas
 *
 * Bound as a singleton and reached through the Warden facade. The registry is
 * built from the `warden.schemas` config.
 */
class WardenManager
{
    /**
     * @var array<class-string<Model>, class-string<WardenSchema>>
     */
    private array $modelsToSchemas = [];

    /**
     * @var array<string, class-string<WardenSchema>>
     */
    private array $permissionBaseNamesToSchemas = [];

    /**
     * @param  array<int, class-string<WardenSchema>>  $schemaClasses
     */
    public function __construct(array $schemaClasses)
    {
        foreach ($schemaClasses as $schemaClass) {
            $model = $schemaClass::model;

            $permissionBaseName = $schemaClass::permissionsBaseName();

            if (isset($this->permissionBaseNamesToSchemas[$permissionBaseName])) {
                throw new InvalidArgumentException('Duplicate schema for permission base name '.$permissionBaseName);
            }

            /* Capability schemas have no model; only model-backed schemas are
               indexed by model class. */
            if ($model !== '') {
                if (isset($this->modelsToSchemas[$model])) {
                    throw new InvalidArgumentException('Duplicate schema for model '.$model);
                }

                $this->modelsToSchemas[$model] = $schemaClass;
            }

            $this->permissionBaseNamesToSchemas[$permissionBaseName] = $schemaClass;
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return class-string<WardenSchema>
     */
    public function getSchemaForModelClass(string $modelClass): string
    {
        if (!isset($this->modelsToSchemas[$modelClass])) {
            throw new OutOfBoundsException(sprintf('No Warden schema registered for model [%s].', $modelClass));
        }

        return $this->modelsToSchemas[$modelClass];
    }

    /**
     * @return class-string<WardenSchema>
     */
    public function getSchemaForPermissionBaseName(string $permissionBaseName): string
    {
        if (!isset($this->permissionBaseNamesToSchemas[$permissionBaseName])) {
            throw new OutOfBoundsException(sprintf('No Warden schema registered for permission base name [%s].', $permissionBaseName));
        }

        return $this->permissionBaseNamesToSchemas[$permissionBaseName];
    }

    /**
     * Combined no-target ability bag for multiple schemas. Each argument may be
     * a WardenSchema class string or a permission base name.
     *
     * @return array<string, array{permission_base_name: string, abilities: array<int, string>, target: null}>
     */
    public function getNoTargetAbilitiesBag(
        ?Authenticatable $user = null,
        string ...$schemaClassesOrPermissionBaseNames
    ): array
    {
        return collect($schemaClassesOrPermissionBaseNames)
            ->map(fn (string $schemaClassOrPermissionBaseName): string => $this->resolveSchemaClass(
                $schemaClassOrPermissionBaseName
            ))
            ->reduce(
                fn (array $combinedBag, string $schemaClass): array => [
                    ...$combinedBag,
                    $schemaClass::permissionsBaseName() => $schemaClass::getNoTargetAbilitiesBag($user),
                ],
                []
            );
    }

    /**
     * The schema classes registered with Warden.
     *
     * @return array<int, class-string<WardenSchema>>
     */
    public function registeredSchemas(): array
    {
        return array_values($this->permissionBaseNamesToSchemas);
    }

    /**
     * @return class-string<WardenSchema>
     */
    private function resolveSchemaClass(string $schemaClassOrPermissionBaseName): string
    {
        if (is_a($schemaClassOrPermissionBaseName, WardenSchema::class, true)) {
            return $schemaClassOrPermissionBaseName;
        }

        return $this->getSchemaForPermissionBaseName($schemaClassOrPermissionBaseName);
    }
}
