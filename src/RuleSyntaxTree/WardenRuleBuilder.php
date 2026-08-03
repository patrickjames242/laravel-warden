<?php

namespace Warden\RuleSyntaxTree;

use LogicException;

/**
 * A fluent, Laravel-query-builder-style front-end for constructing a whole
 * {@see WardenRule} in PHP instead of the string DSL.
 *
 * It extends {@see WardenConditionBuilder} with the clause half of a rule
 * (`theyCan` / `theyCannot`) and finalization via `toRule()`. The condition
 * methods it inherits return `static`, so a top-level chain keeps the rule
 * builder — `->if(...)->theyCan(...)` works — while a group closure only ever
 * receives a bare condition builder.
 *
 * ```php
 * WardenRule::build()
 *     ->if('is_self')
 *     ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
 *     ->theyCan('view', 'update')
 *     ->theyCannot('delete')
 *     ->toRule();
 * ```
 */
final class WardenRuleBuilder extends WardenConditionBuilder
{
    /** @var list<string> */
    private array $can = [];

    /** @var list<string> */
    private array $cannot = [];

    // -- clauses --------------------------------------------------------------

    public function theyCan(string ...$abilities): static
    {
        $this->can = [...$this->can, ...$abilities];

        return $this;
    }

    public function theyCannot(string ...$abilities): static
    {
        $this->cannot = [...$this->cannot, ...$abilities];

        return $this;
    }

    // -- materialization ------------------------------------------------------

    public function toRule(): WardenRule
    {
        if ($this->can === [] && $this->cannot === []) {
            throw new LogicException(
                "A rule needs at least one 'they can ...' or 'they cannot ...' clause; call theyCan() or theyCannot() before toRule()."
            );
        }

        return new WardenRule($this->buildConditions(), $this->can, $this->cannot);
    }
}
