<?php

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Warden\Schema\WardenSchema;

trait HasWardenSchema
{
    /**
     * @return class-string<WardenSchema>
     */
    abstract public function wardenSchema(): string;

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): bool
    {
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->wardenSchema();

        return $schemaClass::userHasAbilities($abilities, $target, $user, $matchMode);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserAbilities(Model|string|null $target = null, ?Authenticatable $user = null): array
    {
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->wardenSchema();

        return $schemaClass::getUserAbilities($target, $user);
    }

    public function scopeHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWardenSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeHasAbility requires an authenticated user or an explicit user instance.');
        }

        $schema->filterQuery(
            currentUser: $user,
            query: $query->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            abilities: $abilities,
            matchMode: $matchMode,
        );

        return $query;
    }

    /**
     * @param  array<int, string>|null  $onlyAbilities  Compute only these per-row
     *   abilities instead of the full set (see selectAbilitiesInQuery).
     */
    public function scopeSelectAbilities(
        EloquentBuilder $query,
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWardenSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeSelectAbilities requires an authenticated user or an explicit user instance.');
        }

        $schema->selectAbilitiesInQuery(
            currentUser: $user,
            query: $query->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            selectedAbilitiesKey: $selectedAbilitiesKey,
            onlyAbilities: $onlyAbilities,
        );

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function loadAbilities(?Authenticatable $user = null, string $selectedAbilitiesKey = 'abilities'): array
    {
        $schemaClass = $this->wardenSchema();

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('loadAbilities requires an authenticated user or an explicit user instance.');
        }

        $abilities = $schemaClass::getUserAbilities($this, $user);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    protected function newWardenSchemaInstance(Model $model): WardenSchema
    {
        $schemaClass = $model->wardenSchema();

        if (!is_a($schemaClass, WardenSchema::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WardenSchema class string, got [%s].', $model::class, $schemaClass)
            );
        }

        if ($schemaClass::model !== $model::class) {
            throw new LogicException(
                sprintf('Schema [%s] must manage model [%s], got [%s].', $schemaClass, $model::class, $schemaClass::model)
            );
        }

        return new $schemaClass;
    }
}
