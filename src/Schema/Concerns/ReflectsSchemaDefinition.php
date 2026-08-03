<?php

namespace Warden\Schema\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Warden\Ability;
use Warden\ConditionWithoutTarget;
use Warden\ConditionWithTarget;

/**
 * Reflection over a schema's declared vocabulary: the abilities (from `#[Ability]`
 * constants) and the conditions (from `#[ConditionWith(out)Target]` methods) that
 * a rule string is allowed to reference.
 */
trait ReflectsSchemaDefinition
{
    /**
     * Returns the schema key for this schema — the namespace prefix used in
     * rules and lookups.
     *
     * The value is derived from the table name of the model referenced by `static::model`.
     * That makes the prefix deterministic and keeps rules aligned with the
     * managed entity in storage.
     *
     * Example:
     * ```php
     * CourseSectionSchema::schemaKey();
     * ```
     *
     * Expected output:
     * ```php
     * 'course_sections'
     * ```
     */
    public static function schemaKey(): string
    {
        if (static::schemaKey !== null) {
            return static::schemaKey;
        }

        $modelClass = static::model;

        /** @var Model $model */
        $model = new $modelClass;

        return $model->getTable();
    }

    /**
     * Returns all targeted condition keys declared by the schema.
     *
     * A targeted condition key is discovered from each public method marked with
     * `#[ConditionWithTarget(...)]`.
     *
     * @return array<int, string>
     */
    public static function targetedConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['has_target'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all no-target condition keys declared by the schema.
     *
     * A no-target condition key is discovered from each public method marked with
     * `#[ConditionWithoutTarget(...)]`.
     *
     * @return array<int, string>
     */
    public static function noTargetConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['no_target'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all condition keys declared by the schema (targeted and no-target).
     *
     * @return array<int, string>
     */
    public static function conditionKeys(): array
    {
        return collect([
            ...static::targetedConditionKeys(),
            ...static::noTargetConditionKeys(),
        ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns the complete list of abilities declared by the schema.
     *
     * Abilities are discovered from class constants marked with `#[Ability]`.
     * Constant naming is not used for discovery.
     *
     * @return array<int, string>
     */
    public static function declaredAbilities(): array
    {
        return collect(static::abilityDefinitions())
            ->pluck('value')
            ->values()
            ->all();
    }

    /**
     * Schemas with no model only answer no-target checks. Guard the targeted
     * paths so they fail with a clear message instead of `new ('')` fataling as
     * "Class \"\" not found".
     */
    protected static function assertSupportsTargetedChecks(): void
    {
        if (static::model === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Schema [%s] is a schema with no model and does not support targeted checks; use a no-target check instead.',
                    static::class
                )
            );
        }
    }

    protected static function conditionKeyFromMethodName(string $methodName): ?string
    {
        $conditionKey = str_starts_with($methodName, 'condition')
            ? Str::after($methodName, 'condition')
            : $methodName;

        if ($conditionKey === '') {
            return null;
        }

        return Str::snake($conditionKey);
    }

    /**
     * @return array<int, array{key: string, method: ReflectionMethod, has_target: bool, no_target: bool}>
     */
    protected static function conditionDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(function (ReflectionMethod $method): ?array {
                if ($method->isStatic()) {
                    return null;
                }

                $withTargetAttributes = $method->getAttributes(ConditionWithTarget::class);
                $withoutTargetAttributes = $method->getAttributes(ConditionWithoutTarget::class);

                if ($withTargetAttributes === [] && $withoutTargetAttributes === []) {
                    return null;
                }

                if (count($withTargetAttributes) > 1 || count($withoutTargetAttributes) > 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must not declare duplicate condition target attributes.',
                        static::class,
                        $method->getName()
                    ));
                }

                if ($withTargetAttributes !== [] && $withoutTargetAttributes !== []) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] cannot declare both #[ConditionWithTarget] and #[ConditionWithoutTarget].',
                        static::class,
                        $method->getName()
                    ));
                }

                $hasTarget = $withTargetAttributes !== [];
                $attributeInstance = $hasTarget
                    ? $withTargetAttributes[0]->newInstance()
                    : $withoutTargetAttributes[0]->newInstance();
                $conditionKey = $attributeInstance->key ?? static::conditionKeyFromMethodName($method->getName());

                if (!is_string($conditionKey) || $conditionKey === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must resolve to a non-empty condition key.',
                        static::class,
                        $method->getName()
                    ));
                }

                $parameterCount = $method->getNumberOfParameters();

                if ($hasTarget && $parameterCount < 3) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must accept a target SQL id parameter when marked #[ConditionWithTarget].',
                        static::class,
                        $method->getName()
                    ));
                }

                /* No-target conditions accept at most (user, whereClause,
                   parameters). A fourth parameter means the author is expecting a
                   target SQL id they will never receive. */
                if (!$hasTarget && $parameterCount > 3) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must not accept a target SQL id parameter when marked #[ConditionWithoutTarget].',
                        static::class,
                        $method->getName()
                    ));
                }

                return [
                    'key' => $conditionKey,
                    'method' => $method,
                    'has_target' => $hasTarget,
                    'no_target' => !$hasTarget,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, method: ReflectionMethod, has_target: bool, no_target: bool}|null
     */
    protected static function conditionDefinitionForKey(string $conditionKey): ?array
    {
        return collect(static::conditionDefinitions())
            ->first(fn(array $definition): bool => $definition['key'] === $conditionKey);
    }

    /**
     * @return array<int, array{value: string}>
     */
    protected static function abilityDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getReflectionConstants())
            ->map(function (ReflectionClassConstant $constant): ?array {
                $attributes = $constant->getAttributes(Ability::class);

                if ($attributes === []) {
                    return null;
                }

                return [
                    'value' => $constant->getValue(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
