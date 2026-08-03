<?php

namespace Warden\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * The seam between a compiled {@see WardenRuleSet} and the host schema. The
 * compiler only knows how to assemble boolean structure and the deny-overrides
 * formula; everything condition-specific (which conditions exist, whether they
 * are row-targeted, and the SQL they emit) is delegated here.
 */
interface ConditionResolver
{
    /**
     * Every ability declared by the schema. Used to expand `*` and to validate
     * ability names.
     *
     * @return array<int, string>
     */
    public static function declaredAbilities(): array;

    /**
     * Whether a condition with this key is declared by the schema.
     */
    public function conditionExists(string $conditionKey): bool;

    /**
     * Whether the keyed condition is row-targeted (needs a target SQL id). A
     * targeted condition is forced to false when compiled without a target.
     */
    public function conditionIsTargeted(string $conditionKey): bool;

    /**
     * Apply a condition's predicate to $whereClause (mutating it) and return the
     * builder, OR return a boolean for conditions that decide the outcome
     * outright (a true/false no-target condition).
     *
     * @param array<int, mixed> $parameters The resolved DSL arguments.
     */
    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        Builder $whereClause,
        ?string $targetSqlId,
        array $parameters,
    ): Builder|bool;
}
