# Warden

Policy-based authorization and permissions for Laravel, with authorization
pushed down into the database query.

Warden turns dot-notation permission strings — `timesheets.update`,
`timesheets.is_department_manager.update`, `timesheets.*` — into consistent
authorization across route middleware, targeted checks, and **query scoping**,
so unauthorized rows never hydrate. Where permissions are *stored* is entirely
up to you: Warden asks a `PermissionResolver` you provide.

## Why Warden? (vs. Spatie laravel-permission, Bouncer, and friends)

Most Laravel "permission" packages solve exactly one part of the problem:
**storing and assigning permission strings, and answering a global yes/no.**
They give you `roles`/`permissions` tables, `$user->givePermissionTo('edit
articles')`, and `$user->can('edit articles')`. That's genuinely useful, and if
your rules are flat and global — "admins can edit articles" — you don't need
Warden.

But "does this user have permission X?" is the easy 10% of a real authorization
system. The hard 90% is everything those packages hand straight back to you:

- **"Which records can they act on?"** `$user->can('update articles')` is a
  single boolean — it can't tell you *which* articles. The moment a rule is
  record-scoped ("a teacher may edit the sections they teach", "a manager may
  approve timesheets in their department"), you're back to hand-writing `where`
  clauses in every controller and keeping them in sync with a *separate* set of
  policy checks. Two encodings of the same rule, guaranteed to drift.
- **Listing thousands of rows.** With a boolean-only package you either load
  everything and filter in PHP (slow, and it breaks pagination and counts) or
  hand-roll the SQL. Warden pushes the rule into the query —
  `Article::query()->hasAbility('update')->paginate()` — so unauthorized rows
  never hydrate, counts and pagination stay correct, and it's one round trip.
- **Relationship-derived permissions.** `timesheets.is_department_manager.update`
  isn't just a flag: the `is_department_manager` condition is a real SQL
  predicate you define once, and Warden reuses it everywhere — the boolean
  check, the query scope, and the per-row abilities sent to the frontend. The
  storage packages have no concept of a condition; a permission is a global
  label, so "manager-scoped" lives in your controllers, not your permissions.
- **One source of truth.** Otherwise the same rule ends up in four places: the
  permission string in the DB, the Gate/policy logic in PHP, the list-filtering
  `where` in each controller, and the UI gating on the frontend — four
  encodings that must agree. A Warden policy defines the abilities, the
  conditions, and how they compile to SQL once, and drives all four — including
  `selectAbilities()`, which attaches each row's computed abilities so the
  frontend can gate buttons without a second copy of the rules.

Warden is **not** a storage library and does not compete with those packages on
that front — it deliberately doesn't own your tables. You hand it a
`PermissionResolver` that returns the permission strings a user holds, from
wherever they live. You can even keep Spatie as your storage/assignment layer
and point Warden's resolver at it: Spatie stores and assigns, Warden evaluates,
scopes queries, and exposes per-row abilities. They sit at different layers.

In short: those packages are a well-built database helper for permission
*strings*. Warden is the part that turns those strings into record-level,
relationship-aware authorization pushed into the database — the part that is
actually hard to build and keep correct as an app grows.

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
