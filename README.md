# Warden

Schema-based authorization and permissions for Laravel, with authorization
pushed down into the database query.

Warden turns dot-notation permission strings — `timesheets.update`,
`timesheets.is_department_manager.update`, `timesheets.*` — into consistent
authorization across route middleware, targeted checks, and **query scoping**,
so unauthorized rows never hydrate. Where permissions are *stored* is entirely
up to you: Warden asks a `PermissionResolver` you provide.

## The permission string

Every grant in Warden is a dot-notation string with two to three segments:

```
base.ability                 timesheets.view
base.*                       timesheets.*                          (every ability on the resource)
base.condition.ability       timesheets.is_department_manager.update
base.condition.*             course_sections.is_department_head.*
```

- **`base`** — the resource, i.e. a schema's base name (`course_sections`,
  `students`, `timesheets`), or a capability with no records (`settings`).
- **`ability`** — the verb the schema declares: `view`, `create`, `update`,
  `delete`, or domain-specific ones like `grade`, `take_attendance`, `manage`.
- **`condition`** — *optional*. A relationship predicate declared on the schema
  (`is_teacher`, `is_department_head`) that narrows the grant to only the rows
  where it holds. This is the piece flat permission strings can't express.
- **`*`** — every ability (optionally within a condition).

### A role is just a set of these strings

Assign them however you like — a roles table, config, JWT claims. Notice how the
*same* resource is granted at different scopes per role:

```php
// Administrator — unrestricted
'course_sections.*',
'students.*',
'timesheets.*',
'settings.manage',                          // capability: a section-level gate, no records

// Teacher — reads everything, but only acts on what they teach
'course_sections.view',                     // view any section
'course_sections.is_teacher.update',        // ...but edit only their own
'course_sections.is_teacher.grade',
'course_sections.is_teacher.take_attendance',
'students.is_teacher.view',                 // only students in their sections

// Department Head — a teacher, plus authority over their department
'course_sections.view',
'course_sections.is_teacher.*',             // everything on sections they teach
'course_sections.is_department_head.*',     // ...and every section in their department
'timesheets.is_department_manager.view',
'timesheets.is_department_manager.update',  // approve their department's timesheets

// Front Office — manages student records, no teaching scope
'students.view',
'students.create',
'students.update',
```

`course_sections` alone is granted three ways here — globally (`view`),
relationship-scoped (`is_teacher.update`), and department-scoped wildcard
(`is_department_head.*`). Adding a rule is adding a string; the schema already
knows how to enforce it everywhere.

## Why Warden? (vs. Spatie laravel-permission, Bouncer, and friends)

Most permission packages store and assign permission strings and answer a global
yes/no. That's the easy 10%. Watch what happens with the Teacher above — *"a
teacher may edit only the sections they teach"* — the moment you need it.

**Without Warden** — the "only their own" rule has nowhere to live but your
controllers, restated every time:

```php
// "Can they update sections?" — a global boolean. It can't say WHICH sections.
$user->can('course_sections.update');

// So listing the ones they may edit is hand-written SQL (rule copy #1):
$sections = CourseSection::query()
    ->whereIn('id', CourseSectionTeacher::where('teacher_id', $user->teacher_id)->select('course_section_id'))
    ->paginate();

// Authorizing a single section — the same rule again, by hand (rule copy #2):
abort_unless(
    $user->can('course_sections.update-any')
        || $section->teachers()->where('teacher_id', $user->teacher_id)->exists(),
    403
);

// Gating the edit button per row on a list — the rule a third time (and an N+1):
$sections->each(fn ($s) => $s->canEdit = /* ...that same check... */);
```

**With Warden** — the rule lives once, as the `is_teacher` condition on
`CourseSectionSchema`, and every site reuses it:

```php
// List — only editable rows hydrate; pagination and counts stay correct; one query:
$sections = CourseSection::query()->hasAbility('update')->paginate();

// Single record — same rule, not restated:
CourseSectionSchema::userHasAbilities('update', $section, $user);

// Per-row gating for the frontend — same rule, computed in the query, no N+1:
CourseSection::query()->selectAbilities()->get();   // each row carries an `abilities` array

// Route — same rule again:
Route::put('/sections/{course_section}', ...)->middleware(WardenMiddleware::canUpdate('course_section'));
```

The `hasAbility('update')` scope expands to exactly the grants the user holds:
an unconditional `course_sections.update` adds nothing (all rows pass), while
`course_sections.is_teacher.update` injects the `is_teacher` predicate — so a
Teacher and an Administrator hit the same call site and get correctly different
result sets, with no `if` ladders.

Warden is **not** a storage library and doesn't compete with those packages
there — it deliberately doesn't own your tables. You hand it a
`PermissionResolver` that returns the strings a user holds, from wherever they
live. You can even keep Spatie as the storage/assignment layer and point
Warden's resolver at it: Spatie stores, Warden evaluates and scopes.

## Installation

```bash
composer require patrickhanna/laravel-warden
```

Publish the config:

```bash
php artisan vendor:publish --tag=warden-config
```

## Concepts

- **Schema** — one `WardenSchema` subclass per resource. It declares the
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

    // Explicit registry of every schema Warden should know about.
    'schemas' => [
        \App\Schemas\TimesheetSchema::class,
    ],
];
```

## Defining a schema

```php
use Warden\WardenSchema;
use Warden\Ability;
use Warden\ConditionWithTarget;
use Warden\StandardAbilities;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

class TimesheetSchema extends WardenSchema
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
TimesheetSchema::userHasAbilities('update', $timesheet, $user);   // targeted
TimesheetSchema::userHasAbilities('create', target: null);        // capability
```

Route middleware (alias `warden`):

```php
Route::put('/timesheets/{timesheet}', ...)
    ->middleware(WardenMiddleware::canUpdate('timesheet'));
```

Add the model trait to enable the query scopes and static helpers:

```php
use Warden\HasWardenSchema;

class Timesheet extends Model
{
    use HasWardenSchema;

    public function wardenSchema(): string
    {
        return TimesheetSchema::class;
    }
}
```

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Query scoping (`selectAbilities`) supports PostgreSQL, MySQL/MariaDB, and SQLite.

## License

MIT
