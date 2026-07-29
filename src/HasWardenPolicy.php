<?php

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait HasWardenPolicy
{
    /**
     * @return class-string<WardenPolicy>
     */
    abstract public function wardenPolicy(): string;

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): bool
    {
        /** @var Model&self $model */
        $model = new static;
        $policyClass = $model->wardenPolicy();

        return $policyClass::userHasAbilities($abilities, $target, $user, $matchMode);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserAbilities(Model|string|null $target = null, ?Authenticatable $user = null): array
    {
        /** @var Model&self $model */
        $model = new static;
        $policyClass = $model->wardenPolicy();

        return $policyClass::getUserAbilities($target, $user);
    }

    public function scopeHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $policy = $this->newWardenPolicyInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeHasAbility requires an authenticated user or an explicit user instance.');
        }

        $policy->filterQuery(
            currentUser: $user,
            query: $query->getQuery(),
            entitySqlId: $model->getQualifiedKeyName(),
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
        $policy = $this->newWardenPolicyInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeSelectAbilities requires an authenticated user or an explicit user instance.');
        }

        $policy->selectAbilitiesInQuery(
            currentUser: $user,
            query: $query->getQuery(),
            entitySqlId: $model->getQualifiedKeyName(),
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
        $policyClass = $this->wardenPolicy();

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('loadAbilities requires an authenticated user or an explicit user instance.');
        }

        $abilities = $policyClass::getUserAbilities($this, $user);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    protected function newWardenPolicyInstance(Model $model): WardenPolicy
    {
        $policyClass = $model->wardenPolicy();

        if (!is_a($policyClass, WardenPolicy::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WardenPolicy class string, got [%s].', $model::class, $policyClass)
            );
        }

        if ($policyClass::model !== $model::class) {
            throw new LogicException(
                sprintf('Policy [%s] must manage model [%s], got [%s].', $policyClass, $model::class, $policyClass::model)
            );
        }

        return new $policyClass;
    }
}
