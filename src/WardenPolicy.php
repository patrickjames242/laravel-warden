<?php

namespace Warden;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;
use Warden\RuleSyntaxTree\ConditionResolver;
use Warden\RuleSyntaxTree\RuleSetCompiler;
use Warden\RuleSyntaxTree\WardenRuleSet;

abstract class WardenPolicy implements ConditionResolver
{
    /**
     * Returns the permission namespace prefix for this policy.
     *
     * The value is derived from the table name of the model referenced by `static::model`.
     * That makes the prefix deterministic and keeps permission strings aligned with the
     * managed entity in storage.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::permissionsBaseName();
     * ```
     *
     * Expected output:
     * ```php
     * 'course_sections'
     * ```
     */
    public static function permissionsBaseName(): string
    {
        if (static::permissionBaseName !== null) {
            return static::permissionBaseName;
        }

        $modelClass = static::model;

        /** @var Model $model */
        $model = new $modelClass;

        return $model->getTable();
    }

    /**
     * Policies with no model only answer no-target checks. Guard the
     * targeted paths so they fail with a clear message instead of `new ('')`
     * fataling as "Class \"\" not found".
     */
    private static function assertSupportsTargetedChecks(): void
    {
        if (static::model === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Policy [%s] is a policy with no model and does not support targeted checks; use a no-target check instead.',
                    static::class
                )
            );
        }
    }

    /**
     * Returns all targeted condition keys declared by the policy.
     *
     * A targeted condition key is discovered from each public method marked with
     * `#[ConditionWithTarget(...)]`.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::targetedConditionKeys();
     * ```
     *
     * Expected output:
     * ```php
     * ['is_teacher']
     * ```
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
     * Returns all no-target condition keys declared by the policy.
     *
     * A no-target condition key is discovered from each public method marked with
     * `#[ConditionWithoutTarget(...)]`.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::noTargetConditionKeys();
     * ```
     *
     * Expected output:
     * ```php
     * []
     * ```
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
     * Returns all condition keys declared by the policy.
     *
     * This combines both targeted and no-target condition keys into one sorted list.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::conditionKeys();
     * ```
     *
     * Expected output:
     * ```php
     * ['is_teacher']
     * ```
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
     * Returns the complete list of abilities declared by the policy.
     *
     * Abilities are discovered from class constants marked with `#[Ability]`.
     * Constant naming is not used for discovery.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::getAbilities();
     * ```
     *
     * Expected output:
     * ```php
     * ['create', 'view', 'update', 'delete']
     * ```
     *
     * @return array<int, string>
     */
    public static function getAbilities(): array
    {
        return collect(static::abilityDefinitions())
            ->pluck('value')
            ->values()
            ->all();
    }

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): bool
    {
        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Policy [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $policy = new static;

        if ($target === null) {
            return $policy->getAbilitiesWithoutEntity($user, $abilities, $matchMode) !== [];
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        return $policy->filterQuery(
            currentUser: $user,
            query: $model->newQuery()->whereKey($targetId)->getQuery(),
            entitySqlId: $model->getQualifiedKeyName(),
            abilities: $abilities,
            matchMode: $matchMode,
        )->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function getUserAbilities(
        Model|string|null $target = null,
        ?Authenticatable $user = null
    ): array
    {
        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Policy [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $policy = new static;

        if ($target === null) {
            return $policy->getAbilitiesWithoutEntity($user);
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        // selectAbilitiesInQuery adds the abilities list via selectSub aliased
        // AS abilities — NOT a real column on the underlying table. Using
        // ->value('abilities') here would call Laravel's first(['abilities']),
        // whose onceWithColumns mechanism replaces the SELECT clause with
        // ['abilities'], wiping the selectSub and yielding null. Read the
        // hydrated row instead so the alias survives.
        $row = (array)$policy->selectAbilitiesInQuery(
            currentUser: $user,
            query: $model->newQuery()->whereKey($targetId)->getQuery(),
            entitySqlId: $model->getQualifiedKeyName(),
        )->first();
        $selectedAbilities = $row['abilities'] ?? null;

        if (is_array($selectedAbilities)) {
            return $selectedAbilities;
        }

        if (!is_string($selectedAbilities) || $selectedAbilities === '') {
            return [];
        }

        $decodedSelectedAbilities = json_decode($selectedAbilities, true);

        return is_array($decodedSelectedAbilities) ? $decodedSelectedAbilities : [];
    }

    /**
     * Returns the no-target access-control bag using the same nested shape as resource helpers.
     *
     * Example:
     * ```php
     * CourseSectionPolicy::getNoTargetAbilitiesBag($user);
     * ```
     *
     * Expected output:
     * ```php
     * [
     *     'course_sections' => [
     *         'permission_base_name' => 'course_sections',
     *         'abilities' => ['create'],
     *         'target' => null,
     *     ],
     * ]
     * ```
     *
     * @return array<string, array{permission_base_name: string, abilities: array<int, string>, target: null}>
     */
    public static function getNoTargetAbilitiesBag(?Authenticatable $user = null): array
    {
        return [
            'permission_base_name' => static::permissionsBaseName(),
            'abilities' => static::getUserAbilities(null, $user),
            'target' => null,
        ];
    }

    /**
     * @var class-string<Model>
     */
    public const model = '';

    /**
     * Explicit permission base name to override the default base name. When null the base name is
     * derived from the model table.
     */
    public const permissionBaseName = null;

    /**
     * @var array<string, true>
     */
    private array $abilityLookup;

    public function __construct()
    {
        $this->abilityLookup = array_fill_keys(static::getAbilities(), true);
    }

    /**
     * Applies a named condition filter to the provided builder.
     *
     * The caller provides the builder that should receive the condition predicate.
     * The named condition must correspond to a public method declared on the policy
     * and marked with either `#[ConditionWithTarget(...)]` or
     * `#[ConditionWithoutTarget(...)]`. The builder is mutated in place and also
     * returned for convenience.
     *
     * Example:
     * ```php
     * $policy->applyConditionFilter(
     *     'is_teacher',
     *     $currentUser,
     *     $query,
     *     'course_sections.id',
     * );
     * ```
     *
     * Expected output:
     * ```php
     * // $query now includes the condition-specific predicate for `is_teacher`
     * ```
     */
    /**
     * @param array<int, mixed> $parameters The resolved DSL arguments for the condition.
     */
    public function applyConditionFilter(
        string $conditionKey,
        Authenticatable $currentUser,
        Builder $whereClause,
        ?string $entitySqlId = null,
        array $parameters = []
    ): mixed
    {
        $conditionDefinition = static::conditionDefinitionForKey($conditionKey);

        if ($conditionDefinition === null) {
            throw new BadMethodCallException(
                sprintf('Condition [%s] is not defined on policy [%s].', $conditionKey, static::class)
            );
        }

        $methodName = $conditionDefinition['method']->getName();

        if ($conditionDefinition['has_target'] && $entitySqlId === null) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] on policy [%s] requires an entity SQL id.', $conditionKey, static::class)
            );
        }

        $arguments = [$currentUser, $whereClause];

        if ($conditionDefinition['has_target']) {
            $arguments[] = $entitySqlId;
        }

        /* Conditions receive their DSL arguments as a trailing bag. Methods that
           ignore parameters simply don't declare the trailing argument; PHP drops
           the extra. */
        $arguments[] = $parameters;

        return $this->{$methodName}(...$arguments);
    }

    // -- ConditionResolver ----------------------------------------------------

    public function declaredAbilities(): array
    {
        return static::getAbilities();
    }

    public function conditionExists(string $name): bool
    {
        return static::conditionDefinitionForKey($name) !== null;
    }

    public function conditionIsTargeted(string $name): bool
    {
        $definition = static::conditionDefinitionForKey($name);

        return $definition !== null && $definition['has_target'];
    }

    public function applyCondition(
        string $name,
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        ?string $entitySqlId,
        array $parameters
    ): \Illuminate\Database\Query\Builder|bool
    {
        return $this->applyConditionFilter($name, $user, $whereClause, $entitySqlId, $parameters);
    }

    /**
     * Restricts the provided entity query to rows the current user can access.
     *
     * Each requested ability is validated against the policy's declared ability set.
     * The resulting SQL combines direct permissions (`x.ability`, `x.*`) and
     * condition-scoped permissions (`x.condition.ability`, `x.condition.*`).
     *
     * `AbilityMatchMode::ALL` requires every requested ability to match for a row.
     * `AbilityMatchMode::ANY` allows a row through if any requested ability matches.
     *
     * Example:
     * ```php
     * $policy->filterQuery(
     *     $currentUser,
     *     DB::table('course_sections'),
     *     'course_sections.id',
     *     ['view', 'update'],
     * );
     * ```
     *
     * Expected output:
     * ```php
     * // Returns the same builder instance with access-control WHERE clauses applied
     * ```
     */
    public function filterQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $entitySqlId,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): Builder
    {
        $abilities = $this->normalizeAbilities($abilities);

        if ($abilities === []) {
            return $query;
        }

        $ruleSet = $this->resolveRuleSet($currentUser);

        return $query->where(function (Builder $outerWhereClause) use (
            $abilities,
            $matchMode,
            $currentUser,
            $query,
            $entitySqlId,
            $ruleSet,
        ) {
            foreach ($abilities as $ability) {
                $abilityConditionQuery = $this->buildAbilityConditionQuery(
                    currentUser: $currentUser,
                    query: $query,
                    entitySqlId: $entitySqlId,
                    ability: $ability,
                    ruleSet: $ruleSet,
                );

                if ($matchMode === AbilityMatchMode::ALL) {
                    $outerWhereClause->addNestedWhereQuery($abilityConditionQuery);
                } else {
                    $outerWhereClause->addNestedWhereQuery($abilityConditionQuery, 'or');
                }

            }
        });
    }

    /**
     * Resolve and validate the rule set that governs this user's access to the
     * managed entity.
     */
    protected function resolveRuleSet(Authenticatable $currentUser): WardenRuleSet
    {
        $resolver = app(PermissionResolver::class);

        $ruleSet = $resolver->resolve(new PermissionResolutionContext(
            permissionBaseName: static::permissionsBaseName(),
            policy: static::class,
            user: $currentUser,
            model: static::model !== '' ? static::model : null,
        ));

        $this->compiler()->validate($ruleSet);

        return $ruleSet;
    }

    protected function compiler(): RuleSetCompiler
    {
        return new RuleSetCompiler($this);
    }

    /**
     * Adds a computed abilities column to every row in the provided entity query.
     *
     * The computed column contains a JSON array of abilities the current user has for
     * that specific row after evaluating both direct and condition-scoped permissions.
     * The base row selection is preserved by ensuring `*` is selected when needed.
     *
     * Example:
     * ```php
     * $policy->selectAbilitiesInQuery(
     *     $currentUser,
     *     DB::table('course_sections'),
     *     'course_sections.id',
     * );
     * ```
     *
     * Expected output:
     * ```php
     * // Each row includes an `abilities` column such as:
     * // ["view","update"]
     * // or
     * // []
     * ```
     */
    /**
     * @param array<int, string>|null $onlyAbilities When given, compute only
     *   these per-row abilities instead of the full declared set. Use it when the
     *   UI gates on a known subset (e.g. just `update` for an Edit button): the
     *   attached subquery grows one UNION branch per ability, so narrowing it from
     *   all abilities to one is a large per-row cost reduction on list endpoints.
     */
    public function selectAbilitiesInQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $entitySqlId,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null
    ): Builder
    {
        if ($query->columns === null) {
            $query->select('*');
        }

        $abilities = $onlyAbilities === null
            ? static::getAbilities()
            : static::normalizeAbilities($onlyAbilities);

        if ($abilities === []) {
            return $query->selectRaw("'[]' as {$selectedAbilitiesKey}");
        }

        $abilitySelectQuery = $this->buildAvailableAbilitiesQuery(
            currentUser: $currentUser,
            query: $query,
            abilities: $abilities,
            entitySqlId: $entitySqlId
        );

        /* The JSON aggregate function differs per driver; default to an empty
           array (json_agg/json_arrayagg yield null over an empty set). */
        $wrappedAbility = $query->getGrammar()->wrap('ability');
        $driver = $query->getConnection()->getDriverName();
        $abilitiesJsonAggregate = match ($driver) {
            'pgsql' => "coalesce(json_agg({$wrappedAbility}), '[]'::json)",
            'mysql', 'mariadb' => "coalesce(json_arrayagg({$wrappedAbility}), json_array())",
            'sqlite' => "coalesce(json_group_array({$wrappedAbility}), json_array())",
            default => throw new RuntimeException(
                sprintf('Warden ability selection does not support the [%s] database driver.', $driver)
            ),
        };

        $aggregateQuery = $query->newQuery()
            ->fromSub($abilitySelectQuery, 'available_abilities')
            ->selectRaw($abilitiesJsonAggregate);

        $query->selectSub(
            $aggregateQuery,
            $selectedAbilitiesKey
        );

        return $query;
    }

    /**
     * Returns the abilities the current user can perform without an entity target.
     *
     * The evaluation uses direct permissions and only conditions that do not
     * require an entity SQL id. When abilities are provided explicitly,
     * `AbilityMatchMode::ALL` returns an empty array unless every requested
     * ability matches in that context.
     *
     * Example:
     * ```php
     * $policy->getAbilitiesWithoutEntity($currentUser);
     * $policy->getAbilitiesWithoutEntity($currentUser, ['create'], AbilityMatchMode::ALL);
     * ```
     *
     * Expected output:
     * ```php
     * ['create']
     * ```
     *
     * @return array<int, string>
     */
    public function getAbilitiesWithoutEntity(
        Authenticatable $currentUser,
        string|array|null $abilities = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ANY
    ): array
    {
        $requestedAbilities = $abilities === null
            ? static::getAbilities()
            : $this->normalizeAbilities($abilities);

        if ($requestedAbilities === []) {
            return [];
        }

        /* A connection to evaluate the ability predicates on (permission lookup
           itself is the resolver's job, on its own connection). No-target
           conditions may reference tenant tables, so a capability policy uses
           the default connection — the current tenant under tenancy. */
        $connection = static::model !== ''
            ? (new (static::model))->getConnection()
            : app('db')->connection();
        $baseQuery = $connection->query();
        $allowedAbilityQuery = $this->buildAvailableAbilitiesQuery(
            currentUser: $currentUser,
            query: $baseQuery,
            abilities: $requestedAbilities
        );

        $allowedAbilities = $baseQuery->newQuery()
            ->fromSub($allowedAbilityQuery, 'available_abilities')
            ->pluck('ability')
            ->all();

        if (
            $matchMode === AbilityMatchMode::ALL
            && count($allowedAbilities) !== count($requestedAbilities)
        ) {
            return [];
        }

        return $allowedAbilities;
    }

    /**
     * @param array<int, string> $abilities
     */
    protected function buildAvailableAbilitiesQuery(
        Authenticatable $currentUser,
        Builder $query,
        array $abilities,
        ?string $entitySqlId = null
    ): Builder
    {
        $abilitySelectQuery = null;
        $ruleSet = $this->resolveRuleSet($currentUser);

        foreach ($abilities as $ability) {
            $singleAbilitySelectQuery = $query->newQuery()
                ->selectRaw('? as "ability"', [$ability]);

            $abilityConditionQuery = $this->buildAbilityConditionQuery(
                currentUser: $currentUser,
                query: $query,
                entitySqlId: $entitySqlId,
                ability: $ability,
                ruleSet: $ruleSet,
            );

            $singleAbilitySelectQuery->where(
                fn(Builder $abilityWhereClause) => $abilityWhereClause->addNestedWhereQuery($abilityConditionQuery)
            );

            if ($abilitySelectQuery === null) {
                $abilitySelectQuery = $singleAbilitySelectQuery;
            } else {
                $abilitySelectQuery->unionAll($singleAbilitySelectQuery);
            }

        }

        return $abilitySelectQuery;
    }

    protected static function conditionKeyFromMethodName(string $methodName): ?string
    {
        $conditionName = str_starts_with($methodName, 'condition')
            ? Str::after($methodName, 'condition')
            : $methodName;

        if ($conditionName === '') {
            return null;
        }

        return Str::snake($conditionName);
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeAbilities(string|array $abilities): array
    {
        $abilities = collect(is_array($abilities) ? $abilities : [$abilities])
            ->filter(fn(mixed $ability) => is_string($ability) && $ability !== '')
            ->values()
            ->all();

        foreach ($abilities as $ability) {
            if (!isset($this->abilityLookup[$ability])) {
                throw new InvalidArgumentException(
                    sprintf('Ability [%s] is not defined on policy [%s].', $ability, static::class)
                );
            }
        }

        return $abilities;
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
                        'Condition method [%s::%s] must accept an entity SQL id parameter when marked #[ConditionWithTarget].',
                        static::class,
                        $method->getName()
                    ));
                }

                /* No-target conditions accept at most (user, whereClause,
                   parameters). A fourth parameter means the author is expecting an
                   entity SQL id they will never receive. */
                if (!$hasTarget && $parameterCount > 3) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must not accept an entity SQL id parameter when marked #[ConditionWithoutTarget].',
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

    protected function buildAbilityConditionQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $ability,
        WardenRuleSet $ruleSet,
        ?string $entitySqlId = null
    ): Builder
    {
        return $this->compiler()->compileAbility(
            $currentUser,
            $query,
            $ability,
            $ruleSet,
            $entitySqlId,
        );
    }
}
