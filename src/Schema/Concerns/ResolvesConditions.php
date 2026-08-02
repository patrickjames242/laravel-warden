<?php

namespace Warden\Schema\Concerns;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * The vocabulary seam the compiler dispatches into (the {@see \Warden\RuleSyntaxTree\ConditionResolver}
 * implementation): validating ability names and applying a named condition's SQL
 * predicate to a builder.
 */
trait ResolvesConditions
{
    /**
     * Applies a named condition filter to the provided builder.
     *
     * The named condition must correspond to a public method declared on the
     * schema and marked with either `#[ConditionWithTarget(...)]` or
     * `#[ConditionWithoutTarget(...)]`. The builder is mutated in place and also
     * returned for convenience.
     *
     * @param array<int, mixed> $parameters The resolved DSL arguments for the condition.
     */
    public function applyConditionFilter(
        string $conditionKey,
        Authenticatable $currentUser,
        Builder $whereClause,
        ?string $entitySqlId = null,
        array $parameters = []
    ): mixed
    {
        $conditionDefinition = static::conditionDefinitionForKey($conditionKey);

        if ($conditionDefinition === null) {
            throw new BadMethodCallException(
                sprintf('Condition [%s] is not defined on schema [%s].', $conditionKey, static::class)
            );
        }

        $methodName = $conditionDefinition['method']->getName();

        if ($conditionDefinition['has_target'] && $entitySqlId === null) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] on schema [%s] requires an entity SQL id.', $conditionKey, static::class)
            );
        }

        $arguments = [$currentUser, $whereClause];

        if ($conditionDefinition['has_target']) {
            $arguments[] = $entitySqlId;
        }

        /* Conditions receive their DSL arguments as a trailing bag. Methods that
           ignore parameters simply don't declare the trailing argument; PHP drops
           the extra. */
        $arguments[] = $parameters;

        return $this->{$methodName}(...$arguments);
    }

    // -- ConditionResolver ----------------------------------------------------

    public function declaredAbilities(): array
    {
        return static::getAbilities();
    }

    public function conditionExists(string $name): bool
    {
        return static::conditionDefinitionForKey($name) !== null;
    }

    public function conditionIsTargeted(string $name): bool
    {
        $definition = static::conditionDefinitionForKey($name);

        return $definition !== null && $definition['has_target'];
    }

    public function applyCondition(
        string $name,
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        ?string $entitySqlId,
        array $parameters
    ): \Illuminate\Database\Query\Builder|bool
    {
        return $this->applyConditionFilter($name, $user, $whereClause, $entitySqlId, $parameters);
    }

    /**
     * Validate and normalize a requested ability list against the schema's
     * declared abilities.
     *
     * @return array<int, string>
     */
    protected function normalizeAbilities(string|array $abilities): array
    {
        $abilities = collect(is_array($abilities) ? $abilities : [$abilities])
            ->filter(fn(mixed $ability) => is_string($ability) && $ability !== '')
            ->values()
            ->all();

        foreach ($abilities as $ability) {
            if (!isset($this->abilityLookup[$ability])) {
                throw new InvalidArgumentException(
                    sprintf('Ability [%s] is not defined on schema [%s].', $ability, static::class)
                );
            }
        }

        return $abilities;
    }
}
