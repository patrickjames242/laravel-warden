<?php

namespace Warden\Schema\Concerns;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;
use Warden\Schema\Conditions\GlobalConditionContext;
use Warden\Schema\Conditions\TargetedConditionContext;

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
     * schema and marked with either `#[TargetedCondition(...)]` or
     * `#[GlobalCondition(...)]`. The method receives a single context object
     * carrying the user, the builder, the DSL arguments, and — for targeted
     * conditions — the target SQL id. The builder is mutated in place and also
     * returned for convenience.
     *
     * @param array<int, mixed> $arguments The resolved DSL arguments for the condition.
     */
    public function applyConditionFilter(
        string $conditionKey,
        Authenticatable $currentUser,
        Builder $whereClause,
        ?string $targetSqlId = null,
        array $arguments = []
    ): mixed
    {
        $conditionDefinition = static::conditionDefinitionForKey($conditionKey);

        if ($conditionDefinition === null) {
            throw new BadMethodCallException(
                sprintf('Condition [%s] is not defined on schema [%s].', $conditionKey, static::class)
            );
        }

        $methodName = $conditionDefinition['method']->getName();

        if ($conditionDefinition['has_target']) {
            if ($targetSqlId === null) {
                throw new InvalidArgumentException(
                    sprintf('Condition [%s] on schema [%s] requires a target SQL id.', $conditionKey, static::class)
                );
            }

            $context = new TargetedConditionContext($currentUser, $whereClause, $targetSqlId, $arguments);
        } else {
            $context = new GlobalConditionContext($currentUser, $whereClause, $arguments);
        }

        return $this->{$methodName}($context);
    }

    // -- ConditionResolver ----------------------------------------------------

    public function conditionExists(string $conditionKey): bool
    {
        return static::conditionDefinitionForKey($conditionKey) !== null;
    }

    public function conditionIsTargeted(string $conditionKey): bool
    {
        $definition = static::conditionDefinitionForKey($conditionKey);

        return $definition !== null && $definition['has_target'];
    }

    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        ?string $targetSqlId,
        array $parameters
    ): \Illuminate\Database\Query\Builder|bool
    {
        return $this->applyConditionFilter($conditionKey, $user, $whereClause, $targetSqlId, $parameters);
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
