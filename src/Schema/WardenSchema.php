<?php

namespace Warden\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warden\AbilityMatchMode;
use Warden\RuleSyntaxTree\ConditionResolver;
use Warden\RuleSyntaxTree\WardenRule;
use Warden\Schema\Concerns\BuildsAccessQueries;
use Warden\Schema\Concerns\ReflectsSchemaDefinition;
use Warden\Schema\Concerns\ResolvesConditions;

/**
 * A Warden schema declares the vocabulary a rule string may reference for one
 * entity: its abilities (`#[Ability]` constants) and its conditions
 * (`#[ConditionWith(out)Target]` methods, which emit SQL). It is NOT where the
 * rules live — those come from the {@see PermissionResolver} as a
 * {@see \Warden\RuleSyntaxTree\WardenRuleSet}, compiled against this schema.
 *
 * The implementation is split across three concerns:
 *  - {@see ReflectsSchemaDefinition} — discovering abilities/conditions via reflection;
 *  - {@see ResolvesConditions}       — the ConditionResolver seam + ability validation;
 *  - {@see BuildsAccessQueries}      — turning a rule set into SQL access predicates.
 *
 * This class itself carries the configuration constants, the instance lifecycle,
 * and the static entry points callers reach for.
 */
abstract class WardenSchema implements ConditionResolver
{
    use ReflectsSchemaDefinition;
    use ResolvesConditions;
    use BuildsAccessQueries;

    /**
     * @var class-string<Model>
     */
    public const model = '';

    /**
     * Explicit permission base name to override the default base name. When null
     * the base name is derived from the model table.
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
     * Rules that are always in force for this schema, regardless of what the
     * resolver returns. They are merged into every resolved rule set before
     * compilation, so they are validated and compiled exactly like resolver
     * rules (deny-overrides still applies across both).
     *
     * Override to establish baseline access — e.g. a super-admin escape hatch or
     * a universal deny:
     *
     * ```php
     * protected function implicitRules(): array
     * {
     *     return [
     *         WardenRule::fromSyntax('if is_super_admin they can *'),
     *         WardenRule::fromSyntax('if is_suspended they cannot *'),
     *     ];
     * }
     * ```
     *
     * @return array<int, WardenRule>
     */
    protected function implicitRules(): array
    {
        return [];
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
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $schema = new static;

        if ($target === null) {
            return $schema->getAbilitiesWithoutEntity($user, $abilities, $matchMode) !== [];
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        return $schema->filterQuery(
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
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $schema = new static;

        if ($target === null) {
            return $schema->getAbilitiesWithoutEntity($user);
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
        $row = (array)$schema->selectAbilitiesInQuery(
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
     * Returns the no-target access-control bag using the same nested shape as
     * resource helpers.
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
}
