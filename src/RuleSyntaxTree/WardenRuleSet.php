<?php

namespace Warden\RuleSyntaxTree;

use InvalidArgumentException;
use Warden\RuleSyntaxTree\Parsing\Parser;

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
        return new self($entityName, Parser::parse($syntax, $bindings));
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
        $flattened = [];

        foreach ($rules as $rule) {
            foreach (is_array($rule) ? $rule : [$rule] as $one) {
                if (! $one instanceof WardenRule) {
                    throw new InvalidArgumentException(
                        sprintf('fromRules expects WardenRule instances, got %s.', get_debug_type($one))
                    );
                }

                $flattened[] = $one;
            }
        }

        return new self($entityName, $flattened);
    }

}