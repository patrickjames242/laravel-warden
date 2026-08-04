<?php

namespace Warden\Schema\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The evaluation context passed to a `#[TargetedCondition]` method: the current
 * user, the where-clause builder the condition constrains, the SQL id of the
 * target row being evaluated, and any resolved DSL arguments.
 *
 * `targetSqlId` is guaranteed present — a targeted condition is never dispatched
 * without a row to evaluate against.
 */
final readonly class TargetedConditionContext
{
    /**
     * @param array<int, mixed> $arguments The resolved DSL arguments.
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $query,
        public string $targetSqlId,
        public array $arguments = [],
    ) {
    }
}
