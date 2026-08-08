<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Warrant\Schema\WarrantSchema;

trait HasWarrantSchema
{
    /**
     * @return class-string<WarrantSchema>
     */
    abstract public function warrantSchema(): string;

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->warrantSchema();

        return $schemaClass::userHasAbilities($abilities, $target, $user, $matchMode, $context);
    }

    /**
     * Check whether the user has the given ability (or abilities) against this
     * model instance. Runs one targeted EXISTS query — avoid calling it per row
     * in a loop; use the selectAbilities/loadAbilities scopes for collections.
     *
     * @param  string|array<int, string>  $abilities
     */
    public function hasAbility(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        return $this->warrantSchema()::userHasAbilities($abilities, $this, $user, $matchMode, $context);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserAbilities(
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        array $context = []
    ): array
    {
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->warrantSchema();

        return $schemaClass::getUserAbilities($target, $user, $context);
    }

    public function scopeHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWarrantSchemaInstance($model);

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
            context: $context,
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
        ?array $onlyAbilities = null,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWarrantSchemaInstance($model);

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
            context: $context,
        );

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function loadAbilities(
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        array $context = []
    ): array
    {
        $schemaClass = $this->warrantSchema();

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('loadAbilities requires an authenticated user or an explicit user instance.');
        }

        $abilities = $schemaClass::getUserAbilities($this, $user, $context);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    protected function newWarrantSchemaInstance(Model $model): WarrantSchema
    {
        $schemaClass = $model->warrantSchema();

        if (!is_a($schemaClass, WarrantSchema::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WarrantSchema class string, got [%s].', $model::class, $schemaClass)
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
