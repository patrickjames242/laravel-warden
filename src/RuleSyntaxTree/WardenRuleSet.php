<?php

namespace Warden\RuleSyntaxTree;

use InvalidArgumentException;
use Warden\RuleSyntaxTree\Parsing\WardenParser;

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
        return new self($entityName, WardenParser::parse($syntax, $bindings));
    }

    /**
     * Build a rule set from already-resolved rules. Accepts a variadic list or a
     * single array, and each element may be a WardenRule or a WardenRuleBuilder
     * (which is finalized via toRule()). Does not accept bindings, and does not
     * allow mixing raw syntax with resolved rules.
     *
     * @param WardenRule|WardenRuleBuilder|array<int, WardenRule|WardenRuleBuilder> ...$rules
     */
    public static function fromRules(
        string $entityName,
        WardenRule|WardenRuleBuilder|array ...$rules,
    ): self {
        $flattened = [];

        foreach ($rules as $rule) {
            foreach (is_array($rule) ? $rule : [$rule] as $one) {
                if ($one instanceof WardenRuleBuilder) {
                    $one = $one->toRule();
                }

                if (! $one instanceof WardenRule) {
                    throw new InvalidArgumentException(
                        sprintf('fromRules expects WardenRule or WardenRuleBuilder instances, got %s.', get_debug_type($one))
                    );
                }

                $flattened[] = $one;
            }
        }

        return new self($entityName, $flattened);
    }

}