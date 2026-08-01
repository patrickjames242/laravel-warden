<?php

namespace Warden\RuleSyntaxTree;

readonly class NotNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $operand,
    ){

    }
}