<?php

namespace Warrant;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Warrant\Facades\Warrant;
use Warrant\Schema\WarrantSchema;

class WarrantMiddleware
{
    /**
     * Build the route middleware string for an access control check.
     *
     * Example:
     * `WarrantMiddleware::string('course_sections', abilities: ['view', 'update'])`
     * returns `warrant:course_sections,view,update`.
     *
     * `WarrantMiddleware::string('course_sections', AbilityMatchMode::ANY, ['view', 'update'])`
     * returns `warrant:course_sections,any,view,update`.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function string(
        string $target,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): string {
        $normalizedAbilities = is_array($abilities) ? array_values($abilities) : [$abilities];
        $segments = ['warrant:'.self::normalizeTarget(
            $target,
        )];

        if ($matchMode !== AbilityMatchMode::ALL) {
            $segments[] = $matchMode->value;
        }

        return implode(',', [
            ...$segments,
            ...$normalizedAbilities,
        ]);
    }

    private static function normalizeTarget(
        string $target
    ): string {
        if (is_subclass_of($target, WarrantSchema::class)) {
            return $target::schemaKey();
        }

        if (is_subclass_of($target, Model::class)) {
            try {
                return Warrant::getSchemaForModelClass($target)::schemaKey();
            } catch (\OutOfBoundsException) {
            }

            /** @var Model $model */
            $model = new $target;

            if (
                method_exists($model, 'warrantSchema')
                && is_a($model->warrantSchema(), WarrantSchema::class, true)
            ) {
                return $model->warrantSchema()::schemaKey();
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Unable to resolve access control schema for model [%s].',
                    $target
                )
            );
        }

        return $target;
    }

    /**
     * Apply an access control middleware group using the generated middleware string.
     *
     * Example:
     * `WarrantMiddleware::guard('course_sections', 'view', fn () => Route::get(...));`
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function guard(
        string $target,
        string|array $abilities,
        Closure $routes,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): void {
        Route::middleware(self::string(
            $target,
            $abilities,
            $matchMode,
        ))->group($routes);
    }

    /**
     * Guard `view` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canView('course_sections')`
     * returns `warrant:course_sections,view`.
     *
     * `WarrantMiddleware::canView('course_section', fn () => Route::get('/sections/{course_section}', ...));`
     * applies the targeted `view` middleware to the grouped route.
     *
     * `WarrantMiddleware::canView(CourseSectionSchema::class)`
     * returns `warrant:course_sections,view`.
     */
    public static function canView(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::VIEW, $routes);
    }

    /**
     * Guard `create` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canCreate('course_sections')`
     * returns `warrant:course_sections,create`.
     *
     * `WarrantMiddleware::canCreate('course_sections', fn () => Route::post('/sections', ...));`
     * applies the no-target `create` middleware to the grouped route.
     *
     * `WarrantMiddleware::canCreate(CourseSectionSchema::class)`
     * returns `warrant:course_sections,create`.
     */
    public static function canCreate(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::CREATE, $routes);
    }

    /**
     * Guard `update` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canUpdate('course_sections')`
     * returns `warrant:course_sections,update`.
     *
     * `WarrantMiddleware::canUpdate('course_section', fn () => Route::put('/sections/{course_section}', ...));`
     * applies the targeted `update` middleware to the grouped route.
     *
     * `WarrantMiddleware::canUpdate(CourseSectionSchema::class)`
     * returns `warrant:course_sections,update`.
     */
    public static function canUpdate(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::UPDATE, $routes);
    }

    /**
     * Guard `delete` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canDelete('course_sections')`
     * returns `warrant:course_sections,delete`.
     *
     * `WarrantMiddleware::canDelete('course_section', fn () => Route::delete('/sections/{course_section}', ...));`
     * applies the targeted `delete` middleware to the grouped route.
     *
     * `WarrantMiddleware::canDelete(CourseSectionSchema::class)`
     * returns `warrant:course_sections,delete`.
     */
    public static function canDelete(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::DELETE, $routes);
    }

    /**
     * Guard `archive` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canArchive('course_sections')`
     * returns `warrant:course_sections,archive`.
     *
     * `WarrantMiddleware::canArchive('course_section', fn () => Route::post('/sections/{course_section}/archive', ...));`
     * applies the targeted `archive` middleware to the grouped route.
     *
     * `WarrantMiddleware::canArchive(CourseSectionSchema::class)`
     * returns `warrant:course_sections,archive`.
     */
    public static function canArchive(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::ARCHIVE, $routes);
    }

    /**
     * Guard the `manage` capability of a section (a capability schema such as
     * `settings`). Unlike the standard abilities, `manage` gates a whole section
     * rather than a model action, so it takes a schema key, never a
     * route-bound model. Two modes: returns the middleware string, or wraps the
     * grouped routes when given a closure.
     *
     * Examples:
     * `WarrantMiddleware::canManage('settings')`
     * returns `warrant:settings,manage`.
     *
     * `WarrantMiddleware::canManage('settings', fn () => Route::get('/settings/...', ...));`
     * wraps the grouped routes in the settings guard.
     */
    public static function canManage(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, 'manage', $routes);
    }

    private static function abilityHelper(
        string $target,
        string $ability,
        ?Closure $routes = null,
    ): ?string {
        if ($routes instanceof Closure) {
            self::guard($target, $ability, $routes);

            return null;
        }

        return self::string($target, $ability);
    }

    public function handle(
        Request $request,
        Closure $next,
        string $target,
        string $matchModeOrFirstAbility,
        string ...$remainingAbilities
    ): Response {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            abort(403);
        }

        $abilityMatchMode = match ($matchModeOrFirstAbility) {
            'all' => AbilityMatchMode::ALL,
            'any' => AbilityMatchMode::ANY,
            default => AbilityMatchMode::ALL,
        };
        $abilities = in_array($matchModeOrFirstAbility, ['all', 'any'], true)
            ? $remainingAbilities
            : [$matchModeOrFirstAbility, ...$remainingAbilities];

        if ($abilities === []) {
            throw new InvalidArgumentException('Access control middleware requires at least one ability.');
        }

        try {
            $schemaClass = Warrant::getSchemaForKey($target);
        } catch (\OutOfBoundsException) {
            $schemaClass = null;
        }
        $resolvedTarget = null;

        if ($schemaClass === null) {
            $resolvedTarget = $request->route($target);

            if (! $resolvedTarget instanceof Model) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Access control middleware route parameter [%s] must resolve to a model instance.',
                        $target
                    )
                );
            }

            try {
                $schemaClass = Warrant::getSchemaForModelClass($resolvedTarget::class);
            } catch (\OutOfBoundsException) {
                $schemaClass = null;
            }
        }

        if ($schemaClass === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to resolve access control schema for [%s].',
                    $target
                )
            );
        }

        if (! $schemaClass::userHasAbilities($abilities, $resolvedTarget, $user, $abilityMatchMode)) {
            abort(403);
        }

        return $next($request);
    }
}
