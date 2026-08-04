<?php

namespace Warden\RuleSyntaxTree;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warden\Facades\Warden;
use Warden\RuleSyntaxTree\Parsing\WardenParser;
use Warden\Schema\WardenSchema;

readonly class WardenRuleSet
{
    public string $schemaKey;

    /**
     * The schema this rule set targets may be given as a schema key string, a
     * {@see WardenSchema} instance or class-string, or a {@see Model} instance or
     * class-string; it is normalized to the schema key.
     *
     * @param Model|WardenSchema|string $schema
     * @param array<int, WardenRule> $rules
     */
    public function __construct(
        Model|WardenSchema|string $schema,
        public array $rules,
    ){
        $this->schemaKey = Warden::resolveSchemaKey($schema);
    }

    /**
     * Build a rule set by parsing raw Warden syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     *
     * @param Model|WardenSchema|string $schema
     */
    public static function fromSyntax(
        Model|WardenSchema|string $schema,
        string $syntax,
        array $bindings = [],
    ): self {
        return new self($schema, WardenParser::parse($syntax, $bindings));
    }

    /**
     * Build a rule set from already-resolved rules. Accepts a variadic list or a
     * single array, and each element may be a WardenRule or a WardenRuleBuilder
     * (which is finalized via toRule()). Does not accept bindings, and does not
     * allow mixing raw syntax with resolved rules.
     *
     * @param Model|WardenSchema|string $schema
     * @param WardenRule|WardenRuleBuilder|array<int, WardenRule|WardenRuleBuilder> ...$rules
     */
    public static function fromRules(
        Model|WardenSchema|string $schema,
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

        return new self($schema, $flattened);
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
     * @param Model|WardenSchema|string $schema
     * @param Closure(callable():WardenRuleBuilder):void $callback
     */
    public static function build(Model|WardenSchema|string $schema, Closure $callback): self
    {
        $builders = [];

        $make = function () use (&$builders): WardenRuleBuilder {
            return $builders[] = new WardenRuleBuilder;
        };

        $callback($make);

        return self::fromRules($schema, $builders);
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

    /**
     * Validate every condition and ability name against the schema registered
     * for this set's schema key, throwing on the first unknown name. Runs before
     * compilation so mistakes surface loudly rather than silently producing an
     * empty predicate.
     *
     * To validate against a schema you already hold, construct a
     * {@see RuleSetValidator} directly rather than routing through the registry.
     */
    public function validate(): void
    {
        $schemaClass = Warden::getSchemaForKey($this->schemaKey);

        (new RuleSetValidator(new $schemaClass))->validate($this);
    }

    /**
     * Validate several rule sets, each against the schema registered for its own
     * schema key. Throws on the first unknown name across the whole batch.
     *
     * Accepts a variadic list or arrays of rule sets.
     *
     * @param WardenRuleSet|array<int, WardenRuleSet> ...$ruleSets
     */
    public static function validateAll(WardenRuleSet|array ...$ruleSets): void
    {
        foreach ($ruleSets as $ruleSet) {
            foreach (is_array($ruleSet) ? $ruleSet : [$ruleSet] as $one) {
                if (! $one instanceof self) {
                    throw new InvalidArgumentException(
                        sprintf('validateAll expects WardenRuleSet instances, got %s.', get_debug_type($one))
                    );
                }

                $one->validate();
            }
        }
    }

}