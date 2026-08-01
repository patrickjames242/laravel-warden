<?php

namespace Warden\RuleSyntaxTree;

class RuleSyntaxExamples
{

    public function examples(){

        $ruleSet = WardenRuleSet::fromSyntax('timesheets', "

            if is_self or (!is_manager and is_specific_user('some_user_id'))
            they can edit, view, delete
            they cannot approve, deny 
            
            if has_access_control_level
            they can edit, view, update
            they cannot publish, deny
            
        ");

        // you can also type other types of literals like is_thing(42, true, null) directly into the string.

        // named bindings, only name matters for matching, can use as many times as you like, order does not matter at all. can used any named parameter anywhere in the string even with multiple rules in the same string.

        //Validation contract. Placeholder with no binding, binding with no placeholder, positional count ≠ ? count — all undefined. These should be hard errors at make() time, not silent.

        $ruleSet = WardenRuleSet::fromSyntax('timesheets', "

            if (!(is_self or (
                !is_manager 
                and is_specific_user(
                    :specific_user_id, 
                    :specific_user_id,
                    :some_list
                )
            )))
            they cannot edit
            
        ", [
            'specific_user_id' => 'some_user_id',
            'some_list' => [
                1, null, false, 'some_string'
            ]
        ]);

        // positional bindings, position determines which binding is for which placeholder


        // you cannot mix positional and named bindings!!

        // positional bindings count left to right accross the entire string

        $ruleSet = WardenRuleSet::fromSyntax('timesheets', "

            if is_department(?, ?, ?)
            they can view
            
        ", [
            'department_id_1',
            'department_id_2',
            'department_id_3',
        ]);

        $individualRule1 = WardenRule::fromSyntax("
            they cannot publish
        ");

        $individualRule2 = WardenRule::fromSyntax("
            they cannot edit
        ");

        $individualRule3 = WardenRule::fromSyntax("
            if some_condition(:some_param)
            they can edit
        ", [
            'some_param' => 'some_value'
        ]);

        // compose rule set of individual already resolved rules. (does not accept bindings. does not allow mixing raw rule syntax with already resolved rules.)
        $ruleSet = WardenRuleSet::fromRules('timesheets', $individualRule1, $individualRule2, $individualRule3);

        $ruleSet = WardenRuleSet::fromRules('timesheets', [$individualRule1, $individualRule2, $individualRule3]);



    }

}