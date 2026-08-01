<?php

namespace Warden\RuleSyntaxTree;

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
        // TODO: parse a single rule from $syntax (resolving $bindings) and construct.
//        throw new \RuntimeException('Not implemented.');
    }

}