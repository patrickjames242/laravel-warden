# Warden

Policy-based authorization and permissions for Laravel, with authorization
pushed down into the database query.

Warden turns dot-notation permission strings — `timesheets.update`,
`timesheets.is_department_manager.update`, `timesheets.*` — into consistent
authorization across route middleware, targeted checks, and **query scoping**,
so unauthorized rows never hydrate. Where permissions are *stored* is entirely
up to you: Warden asks a `PermissionResolver` you provide.

## Installation

```bash
composer require patrickhanna/laravel-warden
```

Publish the config:

```bash
php artisan vendor:publish --tag=warden-config
```

## Concepts

- **Policy** — one `WardenPolicy` subclass per resource. It declares the
  resource's abilities and its conditions, and is the single source of truth for
  how permissions map to SQL.
- **Ability** — a verb on a resource (`view`, `update`, …), declared as an
  `#[Ability]` constant.
- **Condition** — a named predicate (`is_department_manager`) that narrows a
  permission to a subset of rows, declared with `#[ConditionWithTarget]` (a SQL
  predicate) or `#[ConditionWithoutTarget]` (a per-request boolean/SQL).
- **PermissionResolver** — *you* implement this: given a user + base name, return
  the permission strings (or `WardenPermission`s) they hold. Warden ships no
  default resolver.

## Configuration

`config/warden.php`:

```php
return [
    // A class implementing Warden\PermissionResolver. Required — no default.
    'permission_resolver' => \App\Warden\RolePermissionResolver::class,

    // Explicit registry of every policy Warden should know about.
    'policies' => [
        \App\Policies\TimesheetPolicy::class,
    ],
];
```

## Defining a policy

```php
use Warden\WardenPolicy;
use Warden\Ability;
use Warden\ConditionWithTarget;
use Warden\StandardAbilities;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

class TimesheetPolicy extends WardenPolicy
{
    public const model = \App\Models\Timesheet::class;

    #[Ability] public const VIEW = StandardAbilities::VIEW;
    #[Ability] public const UPDATE = StandardAbilities::UPDATE;

    #[ConditionWithTarget]
    public function conditionIsDepartmentManager(
        Authenticatable $currentUser,
        Builder $whereClause,
        string $timesheetSqlId
    ): Builder {
        return $whereClause->whereExists(/* correlate to $timesheetSqlId */);
    }
}
```

A `#[ConditionWithoutTarget]` may return a `bool` — when it returns `true`, the
user has the ability outright.

## Writing a resolver

```php
use Warden\PermissionResolver;
use Warden\PermissionResolutionContext;
use Illuminate\Support\Collection;

class RolePermissionResolver implements PermissionResolver
{
    public function resolve(PermissionResolutionContext $context): iterable
    {
        // Return permission strings (or WardenPermission instances) for
        // $context->user scoped to $context->permissionBaseName.
        return DB::table('role_permissions')
            ->where('role_id', $context->user?->getAuthIdentifier())
            ->pluck('permission');
    }
}
```

## Using it

Query scoping (only rows the user may act on ever load):

```php
Timesheet::query()->hasAbility('update')->get();
```

Attach per-row abilities for the frontend to gate on:

```php
Timesheet::query()->selectAbilities()->get(); // each row gains an `abilities` array
```

Targeted and no-target checks:

```php
TimesheetPolicy::userHasAbilities('update', $timesheet, $user);   // targeted
TimesheetPolicy::userHasAbilities('create', target: null);        // capability
```

Route middleware (alias `warden`):

```php
Route::put('/timesheets/{timesheet}', ...)
    ->middleware(WardenMiddleware::canUpdate('timesheet'));
```

Add the model trait to enable the query scopes and static helpers:

```php
use Warden\HasWardenPolicy;

class Timesheet extends Model
{
    use HasWardenPolicy;

    public function wardenPolicy(): string
    {
        return TimesheetPolicy::class;
    }
}
```

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Query scoping (`selectAbilities`) supports PostgreSQL, MySQL/MariaDB, and SQLite.

## License

MIT
