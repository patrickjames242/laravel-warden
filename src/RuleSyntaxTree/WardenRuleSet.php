<?php

namespace Warden\RuleSyntaxTree;

readonly class WardenRuleSet
{

    /**
     * @param string $entityName
     * @param array<int, WardenRule> $rules
     */
    public function __construct(
        public string $entityName,
        public array $rules,
    ){

    }

    /**
     * Build a rule set by parsing raw Warden syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     */
    public static function fromSyntax(
        string $entityName,
        string $syntax,
        array $bindings = [],
    ): self {
        // TODO: parse $syntax into WardenRule[] (resolving $bindings) and construct.
//        throw new \RuntimeException('Not implemented.');
    }

    /**
     * Build a rule set from already-resolved rules. Accepts either a
     * variadic list of rules or a single array of rules. Does not accept
     * bindings, and does not allow mixing raw syntax with resolved rules.
     *
     * @param WardenRule|array<int, WardenRule> ...$rules
     */
    public static function fromRules(
        string $entityName,
        WardenRule|array ...$rules,
    ): self {
        // TODO: flatten $rules (variadic or a single array) and construct.
//        throw new \RuntimeException('Not implemented.');
    }

}