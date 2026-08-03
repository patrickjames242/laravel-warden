<?php

namespace Warden\RuleSyntaxTree;

use Warden\RuleSyntaxTree\Parsing\WardenParser;

readonly class WardenRule
{

    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canAbilities,
        public array $cannotAbilities,
    ){

    }

    /**
     * Build a single rule by parsing raw Warden syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     */
    public static function fromSyntax(
        string $syntax,
        array $bindings = [],
    ): self {
        return WardenParser::parseSingleRule($syntax, $bindings);
    }

    /**
     * Start a fluent, query-builder-style rule construction.
     */
    public static function build(): WardenRuleBuilder
    {
        return new WardenRuleBuilder;
    }

}