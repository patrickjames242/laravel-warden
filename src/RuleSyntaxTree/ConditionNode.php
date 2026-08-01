<?php

namespace Warden\RuleSyntaxTree;

readonly class ConditionNode implements IBooleanExpressionNode
{

    public function __construct(
        public string $conditionName,
        public array $parameters = [],
    ){

    }

}