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

    public static function make(string $ruleString, array $bindings = []): WardenRule{

    }

}