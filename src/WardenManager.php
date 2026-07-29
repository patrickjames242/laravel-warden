<?php

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Central registry and validation entry point for Warden.
 *
 * Responsible for:
 * - mapping model classes to policy classes
 * - mapping permission base names to policy classes
 * - validating persisted permission strings against registered policies
 *
 * Bound as a singleton and reached through the Warden facade. The registry is
 * built from the `warden.policies` config.
 */
class WardenManager
{
    /**
     * @var array<class-string<Model>, class-string<WardenPolicy>>
     */
    private array $modelsToPolicies = [];

    /**
     * @var array<string, class-string<WardenPolicy>>
     */
    private array $permissionBaseNamesToPolicies = [];

    /**
     * @param  array<int, class-string<WardenPolicy>>  $policyClasses
     */
    public function __construct(array $policyClasses)
    {
        foreach ($policyClasses as $policyClass) {
            $model = $policyClass::model;

            $permissionBaseName = $policyClass::permissionsBaseName();

            if (isset($this->permissionBaseNamesToPolicies[$permissionBaseName])) {
                throw new InvalidArgumentException('Duplicate policy for permission base name '.$permissionBaseName);
            }

            /* Capability policies have no model; only model-backed policies are
               indexed by model class. */
            if ($model !== '') {
                if (isset($this->modelsToPolicies[$model])) {
                    throw new InvalidArgumentException('Duplicate policy for model '.$model);
                }

                $this->modelsToPolicies[$model] = $policyClass;
            }

            $this->permissionBaseNamesToPolicies[$permissionBaseName] = $policyClass;
        }
    }

    /**
     * Validate one or more persisted permission strings against the registered policies.
     *
     * Accepted formats: `base.ability`, `base.condition.ability`, `base.*`,
     * `base.condition.*`. The input is returned unchanged after validation.
     *
     * @param  string|array<array-key, string>  $permissionStrings
     * @return string|array<array-key, string>
     */
    public function validatePermissionStrings(string|array $permissionStrings): string|array
    {
        $normalizedPermissionStrings = is_array($permissionStrings)
            ? $permissionStrings
            : [$permissionStrings];

        foreach ($normalizedPermissionStrings as $permissionString) {
            $segments = explode('.', $permissionString);

            if (count($segments) < 2 || count($segments) > 3) {
                throw new InvalidArgumentException(
                    sprintf('Warden permission string [%s] is invalid.', $permissionString)
                );
            }

            [$permissionBaseName, $conditionOrAbility, $abilityOrWildcard] = array_pad($segments, 3, null);

            try {
                $policyClass = $this->getPolicyForPermissionBaseName($permissionBaseName);
            } catch (OutOfBoundsException) {
                throw new InvalidArgumentException(
                    sprintf('Warden permission string [%s] has an invalid permission base name.', $permissionString)
                );
            }

            $validAbilities = $policyClass::getAbilities();
            $validConditions = $policyClass::conditionKeys();

            if ($abilityOrWildcard === null) {
                if ($conditionOrAbility !== '*' && !in_array($conditionOrAbility, $validAbilities, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Warden permission string [%s] has an invalid ability.', $permissionString)
                    );
                }

                continue;
            }

            if (!in_array($conditionOrAbility, $validConditions, true)) {
                throw new InvalidArgumentException(
                    sprintf('Warden permission string [%s] has an invalid condition.', $permissionString)
                );
            }

            if ($abilityOrWildcard !== '*' && !in_array($abilityOrWildcard, $validAbilities, true)) {
                throw new InvalidArgumentException(
                    sprintf('Warden permission string [%s] has an invalid ability.', $permissionString)
                );
            }
        }

        return $permissionStrings;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return class-string<WardenPolicy>
     */
    public function getPolicyForModelClass(string $modelClass): string
    {
        if (!isset($this->modelsToPolicies[$modelClass])) {
            throw new OutOfBoundsException(sprintf('No Warden policy registered for model [%s].', $modelClass));
        }

        return $this->modelsToPolicies[$modelClass];
    }

    /**
     * @return class-string<WardenPolicy>
     */
    public function getPolicyForPermissionBaseName(string $permissionBaseName): string
    {
        if (!isset($this->permissionBaseNamesToPolicies[$permissionBaseName])) {
            throw new OutOfBoundsException(sprintf('No Warden policy registered for permission base name [%s].', $permissionBaseName));
        }

        return $this->permissionBaseNamesToPolicies[$permissionBaseName];
    }

    /**
     * Combined no-target ability bag for multiple policies. Each argument may be
     * a WardenPolicy class string or a permission base name.
     *
     * @return array<string, array{permission_base_name: string, abilities: array<int, string>, target: null}>
     */
    public function getNoTargetAbilitiesBag(
        ?Authenticatable $user = null,
        string ...$policyClassesOrPermissionBaseNames
    ): array
    {
        return collect($policyClassesOrPermissionBaseNames)
            ->map(fn (string $policyClassOrPermissionBaseName): string => $this->resolvePolicyClass(
                $policyClassOrPermissionBaseName
            ))
            ->reduce(
                fn (array $combinedBag, string $policyClass): array => [
                    ...$combinedBag,
                    $policyClass::permissionsBaseName() => $policyClass::getNoTargetAbilitiesBag($user),
                ],
                []
            );
    }

    /**
     * The policy classes registered with Warden.
     *
     * @return array<int, class-string<WardenPolicy>>
     */
    public function registeredPolicies(): array
    {
        return array_values($this->permissionBaseNamesToPolicies);
    }

    /**
     * @return class-string<WardenPolicy>
     */
    private function resolvePolicyClass(string $policyClassOrPermissionBaseName): string
    {
        if (is_a($policyClassOrPermissionBaseName, WardenPolicy::class, true)) {
            return $policyClassOrPermissionBaseName;
        }

        return $this->getPolicyForPermissionBaseName($policyClassOrPermissionBaseName);
    }
}
