<?php

namespace Warden\RuleSyntaxTree;

use Closure;
use InvalidArgumentException;
use Warden\RuleSyntaxTree\Parsing\WardenParser;

readonly class WardenRuleSet
{

    /**
     * @param string $schemaKey
     * @param array<int, WardenRule> $rules
     */
    public function __construct(
        public string $schemaKey,
        public array $rules,
    ){

    }

    /**
     * Build a rule set by parsing raw Warden syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     */
    public static function fromSyntax(
        string $schemaKey,
        string $syntax,
        array $bindings = [],
    ): self {
        return new self($schemaKey, WardenParser::parse($syntax, $bindings));
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
        string $schemaKey,
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

        return new self($schemaKey, $flattened);
    }

    /**
     * Build a rule set with a callback, one rule per `$rule()` call.
     *
     * The callback receives a factory; each invocation of it appends a fresh
     * {@see WardenRuleBuilder} to the set and returns it for chaining. Rules are
     * finalized automatically — there is no need to call toRule().
     *
     * ```php
     * WardenRuleSet::build('timesheets', function ($rule) {
     *     $rule()->if('is_self')->theyCan('edit', 'view');
     *     $rule()->theyCan('list');
     * });
     * ```
     *
     * @param Closure(callable():WardenRuleBuilder):void $callback
     */
    public static function build(string $schemaKey, Closure $callback): self
    {
        $builders = [];

        $make = function () use (&$builders): WardenRuleBuilder {
            return $builders[] = new WardenRuleBuilder;
        };

        $callback($make);

        return self::fromRules($schemaKey, $builders);
    }

    /**
     * Render every rule back to the string DSL with scalar condition parameters
     * inlined as literals, one blank line between rules. Throws if a parameter
     * has no inline representation — use {@see toBoundSyntax()} for those.
     * Round-trips via `WardenRuleSet::fromSyntax($this->schemaKey, $syntax)`.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::toSyntax(...$this->rules);
    }

    /**
     * Render every rule to `?`-parameterized syntax plus one flat, left-to-right
     * positional bindings list spanning the whole set. Lossless for any value.
     * Round-trips via
     * `WardenRuleSet::fromSyntax($this->schemaKey, $result->syntax, $result->bindings)`.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::toBoundSyntax(...$this->rules);
    }

}