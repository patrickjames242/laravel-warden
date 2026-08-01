<?php

namespace Warden\RuleSyntaxTree;

readonly class AndNode implements IBooleanExpressionNode
{
    public function __construct(
        IBooleanExpressionNode $leftSide,
        IBooleanExpressionNode $rightSide,
    ){

    }

}