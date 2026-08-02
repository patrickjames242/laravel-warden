<?php

namespace Warden\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Compiles a {@see WardenRuleSet} into SQL predicates.
 *
 * The unit of output is one nested {@see Builder} predicate per ability, ready
 * to be attached to a host query (directly for row filtering, or inside a
 * correlated subquery for per-row ability selection).
 *
 * Per ability A the predicate is:
 *
 *     ( OR of every `can` rule's if-expression that lists A or * )
 *       AND ( AND of NOT(every `cannot` rule's if-expression that lists A or *) )
 *
 * with these hard edges (deny-overrides):
 *   - an unconditional `cannot` (null if-expression) makes A impossible → 1 = 0;
 *   - an ability with no `can` rule is never granted → 1 = 0;
 *   - an unconditional `can` contributes an always-true term → 1 = 1.
 *
 * Condition leaves are wrapped as EXISTS subqueries so each is a strict boolean
 * (NULL → false) and negation via NOT EXISTS is exact — no three-valued-logic
 * surprises leak into authorization results.
 */
final class RuleSetCompiler
{
    public function __construct(private readonly ConditionResolver $conditions)
    {
    }

    /**
     * Validate every condition and ability name in the rule set against the
     * schema. Runs before compilation so unknown names fail loudly.
     */
    public function validate(WardenRuleSet $ruleSet): void
    {
        $declaredAbilities = $this->conditions->declaredAbilities();

        foreach ($ruleSet->rules as $rule) {
            foreach ([...$rule->canAbilities, ...$rule->cannotAbilities] as $ability) {
                if ($ability !== '*' && ! in_array($ability, $declaredAbilities, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Ability [%s] is not declared by the schema.', $ability)
                    );
                }
            }

            if ($rule->conditions !== null) {
                $this->validateConditionNames($rule->conditions);
            }
        }
    }

    /**
     * Build the predicate for a single ability as a nested query on $query.
     */
    public function compileAbility(
        Authenticatable $user,
        Builder $query,
        string $ability,
        WardenRuleSet $ruleSet,
        ?string $entitySqlId = null,
    ): Builder {
        $predicate = $query->newQuery();

        /** @var list<IBooleanExpressionNode|null> $grants */
        $grants = [];
        /** @var list<IBooleanExpressionNode|null> $denies */
        $denies = [];

        foreach ($ruleSet->rules as $rule) {
            if ($this->listsAbility($rule->canAbilities, $ability)) {
                $grants[] = $rule->conditions;
            }

            if ($this->listsAbility($rule->cannotAbilities, $ability)) {
                $denies[] = $rule->conditions;
            }
        }

        // An unconditional `cannot` denies the ability outright, no matter what.
        foreach ($denies as $denyExpression) {
            if ($denyExpression === null) {
                return $predicate->whereRaw('1 = 0');
            }
        }

        // No `can` rule grants this ability.
        if ($grants === []) {
            return $predicate->whereRaw('1 = 0');
        }

        // Grant side: OR of every can-expression (null => always-true term).
        $predicate->where(function (Builder $grantGroup) use ($grants, $user, $entitySqlId): void {
            foreach ($grants as $index => $grantExpression) {
                $boolean = $index === 0 ? 'and' : 'or';

                if ($grantExpression === null) {
                    $grantGroup->whereRaw('1 = 1', [], $boolean);

                    continue;
                }

                $this->applyExpression($grantGroup, $grantExpression, $user, $entitySqlId, $boolean, false);
            }
        });

        // Deny side: AND NOT(expression) for each conditional `cannot`.
        foreach ($denies as $denyExpression) {
            $predicate->where(function (Builder $denyGroup) use ($denyExpression, $user, $entitySqlId): void {
                $this->applyExpression($denyGroup, $denyExpression, $user, $entitySqlId, 'and', true);
            });
        }

        return $predicate;
    }

    private function validateConditionNames(IBooleanExpressionNode $node): void
    {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionExists($node),
            $node instanceof NotNode => $this->validateConditionNames($node->operand),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node): void {
                $this->validateConditionNames($node->leftSide);
                $this->validateConditionNames($node->rightSide);
            })(),
            default => null,
        };
    }

    private function assertConditionExists(ConditionNode $node): void
    {
        if (! $this->conditions->conditionExists($node->conditionName)) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] is not declared by the schema.', $node->conditionName)
            );
        }
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * Add $node's predicate to $parent under the given boolean connector,
     * negating via De Morgan so that negation always lands on the leaves (where
     * EXISTS / NOT EXISTS keeps it a strict boolean).
     */
    private function applyExpression(
        Builder $parent,
        IBooleanExpressionNode $node,
        Authenticatable $user,
        ?string $entitySqlId,
        string $boolean,
        bool $negate,
    ): void {
        if ($node instanceof NotNode) {
            $this->applyExpression($parent, $node->operand, $user, $entitySqlId, $boolean, ! $negate);

            return;
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            // NOT(a AND b) = NOT a OR NOT b ; NOT(a OR b) = NOT a AND NOT b.
            $childrenAreOr = $node instanceof OrNode;
            $innerSecondBoolean = ($childrenAreOr xor $negate) ? 'or' : 'and';

            $parent->where(function (Builder $group) use ($node, $user, $entitySqlId, $negate, $innerSecondBoolean): void {
                $this->applyExpression($group, $node->leftSide, $user, $entitySqlId, 'and', $negate);
                $this->applyExpression($group, $node->rightSide, $user, $entitySqlId, $innerSecondBoolean, $negate);
            }, null, null, $boolean);

            return;
        }

        if ($node instanceof ConditionNode) {
            $this->applyCondition($parent, $node, $user, $entitySqlId, $boolean, $negate);

            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported expression node [%s].', $node::class));
    }

    private function applyCondition(
        Builder $parent,
        ConditionNode $node,
        Authenticatable $user,
        ?string $entitySqlId,
        string $boolean,
        bool $negate,
    ): void {
        // A targeted condition cannot be evaluated without a row; force it false
        // (so `not <targeted>` becomes true) in a no-target compile.
        if ($entitySqlId === null && $this->conditions->conditionIsTargeted($node->conditionName)) {
            $parent->whereRaw($negate ? '1 = 1' : '1 = 0', [], $boolean);

            return;
        }

        $existsQuery = $parent->newQuery();
        $existsQuery->selectRaw('1')->fromSub(
            fn (Builder $one) => $one->selectRaw('1'),
            'warden_exists'
        );

        $result = $this->conditions->applyCondition(
            $node->conditionName,
            $user,
            $existsQuery,
            $entitySqlId,
            $node->parameters,
        );

        // A no-target condition may decide the outcome outright.
        if (is_bool($result)) {
            $value = $negate ? ! $result : $result;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $boolean);

            return;
        }

        $parent->addWhereExistsQuery($existsQuery, $boolean, $negate);
    }
}
