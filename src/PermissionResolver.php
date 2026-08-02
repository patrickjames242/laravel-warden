<?php

declare(strict_types=1);

namespace Warden;

use Warden\RuleSyntaxTree\WardenRuleSet;

interface PermissionResolver
{
    /**
     * Return the rule set that governs this user's access to the entity in
     * $context. The rule set is compiled directly to SQL, so the implementation
     * is free to build it however it likes (WardenRuleSet::fromSyntax, a database
     * lookup, hardcoded rules, ...).
     */
    public function resolve(PermissionResolutionContext $context): WardenRuleSet;
}
