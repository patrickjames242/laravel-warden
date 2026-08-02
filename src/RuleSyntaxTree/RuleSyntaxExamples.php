<?php

namespace Warden\RuleSyntaxTree;

/**
 * Living reference for Warden rule syntax.
 *
 * Every method below is illustrative and never executed — each demonstrates one
 * facet of the language accepted by WardenRuleSet::fromSyntax() /
 * WardenRule::fromSyntax(), or the resolved-rule composition accepted by
 * WardenRuleSet::fromRules().
 *
 * Core model:
 *  - A rule set compiles DIRECTLY to SQL. There is no in-memory evaluator; even a
 *    single-instance check runs as a scoped SQL query.
 *  - Conditions (is_self, is_manager, is_specific_user, ...) are Warden conditions
 *    that emit SQL. Conditions may take parameters, e.g. is_specific_user('id').
 *  - `cannot` is deny-overrides: each `cannot` rule contributes
 *    `AND NOT (its if-expression)` to every ability it lists. An unconditional
 *    `they cannot X` compiles to `AND NOT (true)` — X is impossible, full stop,
 *    regardless of any `can` rule. Per ability the compiled predicate is:
 *        ( OR of all can-expressions ) AND ( AND of NOT(each cannot-expression) )
 *  - Whitespace and newlines are INSIGNIFICANT; a whole rule set may sit on one
 *    line. `if` (as a standalone keyword) is the sole rule delimiter.
 *  - Bindings are resolved inline at parse time; the resulting tree holds only
 *    concrete values (no placeholder nodes, no separate resolve phase).
 *  - Malformed syntax throws WardenSyntaxException eagerly at build time, with
 *    position information (line:col / offset) and a snippet.
 *
 * Lexical rules:
 *  - Identifiers (condition names, ability names, :binding names): must start with
 *    a letter or underscore, then letters / digits / underscores / dashes —
 *    `[A-Za-z_][A-Za-z0-9_-]*`. No dots.
 *  - Reserved words (cannot be used as an EXACT condition or ability name, though a
 *    name may start with or contain them): if, they, can, cannot, and, or, not.
 *  - String literals: single-quoted, with `\'` and `\\` escapes.
 *  - Operators: `and`, `or`, and negation as `not` (canonical) or `!` (synonym).
 *    Precedence, tightest to loosest: `not`/`!` > `and` > `or`. Parentheses override.
 *    (`&&` / `||` are NOT supported.)
 */
class RuleSyntaxExamples
{
    // -------------------------------------------------------------------------
    // 1. Basics
    // -------------------------------------------------------------------------

    /** A single rule: one `if`, a `can` line, and a `cannot` line. */
    public function basicRule(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_self
            they can edit, view, delete
            they cannot approve, deny
            DSL);

        // Compiles per ability, for this rule:
        //   edit / view / delete  ->  is_self
        //   approve / deny        ->  NOT (is_self)
    }

    /** Several rules in one string. Each `if` starts a new rule. */
    public function multipleRules(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_self or (not is_manager and is_specific_user('some-user-id'))
            they can edit, view, delete
            they cannot approve, deny

            if has_access_control_level
            they can edit, view, update
            they cannot publish, deny
            DSL);

        // The same ability may appear in multiple rules; the per-ability formula
        // ORs the `can` expressions and ANDs the negated `cannot` expressions.
    }

    /** No `if` → the rule always applies (compiles to `WHERE true` on the grant side). */
    public function unconditionalRule(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            they can view
            they cannot delete
            DSL);

        // view   -> true            (always granted)
        // delete -> NOT (true)      (never granted, by anyone)
    }

    // -------------------------------------------------------------------------
    // 2. Whitespace is insignificant
    // -------------------------------------------------------------------------

    /** An entire rule set — multiple `if`s, multiple rules — on a single line. */
    public function singleLine(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax(
            'timesheets',
            'if is_self they can edit if is_manager they can approve they cannot delete'
        );

        // Two rules:
        //   is_self    -> can edit
        //   is_manager -> can approve, cannot delete
        // (`they cannot delete` attaches to the most recent `if`, i.e. is_manager.)
    }

    // -------------------------------------------------------------------------
    // 3. Boolean expressions
    // -------------------------------------------------------------------------

    /** Operator precedence: `not`/`!` > `and` > `or`; parentheses override. */
    public function booleanPrecedence(): void
    {
        // Parses as: is_self OR ((NOT is_manager) AND is_owner)
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_self or not is_manager and is_owner
            they can view
            DSL);
    }

    /** `!` is an accepted synonym for `not`; parentheses group freely. */
    public function negationSynonymAndGrouping(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if !(is_self or (!is_manager and is_specific_user('some-user-id')))
            they cannot edit
            DSL);

        // Equivalent, using canonical `not`:
        //   if not (is_self or (not is_manager and is_specific_user('some-user-id')))
    }

    // -------------------------------------------------------------------------
    // 4. Condition parameters — inline literals
    // -------------------------------------------------------------------------

    /**
     * Inline literals may be: string, int, float, bool, null.
     * (Lists / arbitrary values are only available via bindings — see section 5.)
     */
    public function inlineLiterals(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_thing('a-string', 42, 3.14, true, null)
            they can view
            DSL);
    }

    /**
     * The schema side of a parameterised condition.
     *
     * A condition's DSL arguments arrive as a single trailing `array $parameters`
     * bag (the resolved ConditionNode::$parameters). The condition indexes it and
     * is responsible for binding every value as a placeholder — never
     * interpolating it into SQL. The bag is always the last argument, after the
     * entity SQL id for targeted conditions:
     *
     *   use Illuminate\Contracts\Auth\Authenticatable;
     *   use Illuminate\Contracts\Database\Query\Builder;
     *   use Warden\ConditionWithTarget;
     *   use Warden\ConditionWithoutTarget;
     *
     *   // Targeted: (user, whereClause, entitySqlId, parameters)
     *   #[ConditionWithTarget]
     *   public function conditionIsSpecificUser(
     *       Authenticatable $user,
     *       Builder $where,
     *       string $entitySqlId,
     *       array $parameters,
     *   ): Builder {
     *       // is_specific_user('some-user-id') -> $parameters[0] === 'some-user-id'
     *       return $where->whereRaw("{$entitySqlId} = ?", [$parameters[0]]);
     *   }
     *
     *   // Variadic / list argument -> a whereIn:
     *   #[ConditionWithTarget]
     *   public function conditionIsDepartment(
     *       Authenticatable $user,
     *       Builder $where,
     *       string $entitySqlId,
     *       array $parameters,
     *   ): Builder {
     *       // is_department(?, ?, ?) with positional bindings ['a', 'b', 'c']
     *       return $where->whereIn($entitySqlId, $parameters);
     *   }
     *
     *   // No-target boolean condition: (user, ...) returning true/false.
     *   #[ConditionWithoutTarget]
     *   public function conditionIsSuperUser(Authenticatable $user): bool {
     *       return $user->isSuperUser();
     *   }
     *
     * Conditions that ignore arguments simply omit the trailing bag; PHP drops
     * the extra argument.
     */
    public function conditionParameterContract(): void
    {
        // Documentation only — see the docblock above.
    }

    // -------------------------------------------------------------------------
    // 5. Condition parameters — bindings
    // -------------------------------------------------------------------------

    /**
     * Named bindings (:name). Only the name matters: order in the array is
     * irrelevant, a name may be reused any number of times, and it may appear
     * anywhere in the string — even across multiple rules. A binding value may be
     * ANY PHP value (scalars, null, lists, objects, ...).
     */
    public function namedBindings(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if not (is_self or (not is_manager and is_specific_user(:specific_user_id, :specific_user_id, :some_list)))
            they cannot edit
            DSL, [
            'specific_user_id' => 'some-user-id',
            'some_list' => [1, null, false, 'some-string'],
        ]);
    }

    /**
     * Positional bindings (?). Values are matched left-to-right across the ENTIRE
     * string. Positional and named bindings may NOT be mixed in the same call.
     */
    public function positionalBindings(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_department(?, ?, ?)
            they can view
            DSL, [
            'department-id-1',
            'department-id-2',
            'department-id-3',
        ]);
    }

    // -------------------------------------------------------------------------
    // 6. Wildcard abilities
    // -------------------------------------------------------------------------

    /**
     * `*` means "every ability" (expanded against the schema's declared abilities
     * at compile time). Works on both `can` and `cannot`.
     */
    public function wildcards(): void
    {
        $ruleSet = WardenRuleSet::fromSyntax('timesheets', <<<'DSL'
            if is_admin
            they can *

            if is_suspended
            they cannot *
            DSL);

        // is_admin     -> grants every ability
        // is_suspended -> AND NOT (is_suspended) applied to every ability
        //                 (blanket lockout; deny-overrides still wins)
    }

    // -------------------------------------------------------------------------
    // 7. Composing already-resolved rules (fromRules)
    // -------------------------------------------------------------------------

    /**
     * A single WardenRule can be built on its own (with its own bindings) and later
     * composed into a set. `fromRules` accepts either a variadic list or a single
     * array. It takes NO bindings, and does NOT allow mixing raw syntax with
     * already-resolved rules.
     */
    public function composeResolvedRules(): void
    {
        $cannotPublish = WardenRule::fromSyntax('they cannot publish');
        $cannotEdit    = WardenRule::fromSyntax('they cannot edit');
        $canEdit       = WardenRule::fromSyntax(
            'if some_condition(:some_param) they can edit',
            ['some_param' => 'some-value']
        );

        // Variadic:
        $ruleSet = WardenRuleSet::fromRules('timesheets', $cannotPublish, $cannotEdit, $canEdit);

        // Or a single array:
        $ruleSet = WardenRuleSet::fromRules('timesheets', [$cannotPublish, $cannotEdit, $canEdit]);
    }

    // -------------------------------------------------------------------------
    // 8. Invalid input — each throws WardenSyntaxException at build time
    // -------------------------------------------------------------------------

    /**
     * The following are all INVALID and throw WardenSyntaxException (eagerly, with
     * position info) at build time. Shown as comments so this file stays loadable.
     */
    public function invalidExamples(): void
    {
        // Mixing named and positional bindings in one call:
        //   if is_thing(:a, ?) they can view

        // A named placeholder with no matching binding:
        //   fromSyntax("if is_thing(:missing) they can view", [])

        // A binding that is never referenced by any placeholder:
        //   fromSyntax("if is_self they can view", ['unused' => 1])

        // Positional count mismatch (2 placeholders, 3 values — or vice versa):
        //   fromSyntax("if is_thing(?, ?) they can view", [1, 2, 3])

        // A bare `if` with no `can` / `cannot` lines (grants/denies nothing):
        //   if is_self

        // A reserved word used as an EXACT condition or ability name:
        //   they can can            // ability named exactly `can`
        //   if if they can view     // condition named exactly `if`
        //   (Allowed — they merely contain/start with a reserved word:
        //    `canonical`, `cannot_publish`, `ifield`.)

        // fromRules mixing resolved rules with raw syntax, or being handed bindings:
        //   WardenRuleSet::fromRules('timesheets', $resolvedRule, 'they can view');
    }
}
