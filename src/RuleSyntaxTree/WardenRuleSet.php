<?php

namespace Warden\RuleSyntaxTree;

use Illuminate\Support\Collection;

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

    public static function make(string $entityName, mixed ...$rules): WardenRuleSet{

    }

}