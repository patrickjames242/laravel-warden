<?php

namespace Warden\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * The seam between a compiled {@see WardenRuleSet} and the host policy. The
 * compiler only knows how to assemble boolean structure and the deny-overrides
 * formula; everything condition-specific (which conditions exist, whether they
 * are row-targeted, and the SQL they emit) is delegated here.
 */
interface ConditionResolver
{
    /**
     * Every ability declared by the policy. Used to expand `*` and to validate
     * ability names.
     *
     * @return array<int, string>
     */
    public function declaredAbilities(): array;

    /**
     * Whether a condition with this name is declared by the policy.
     */
    public function conditionExists(string $name): bool;

    /**
     * Whether the named condition is row-targeted (needs an entity SQL id). A
     * targeted condition is forced to false when compiled without a target.
     */
    public function conditionIsTargeted(string $name): bool;

    /**
     * Apply a condition's predicate to $whereClause (mutating it) and return the
     * builder, OR return a boolean for conditions that decide the outcome
     * outright (a true/false no-target condition).
     *
     * @param array<int, mixed> $parameters The resolved DSL arguments.
     */
    public function applyCondition(
        string $name,
        Authenticatable $user,
        Builder $whereClause,
        ?string $entitySqlId,
        array $parameters,
    ): Builder|bool;
}
