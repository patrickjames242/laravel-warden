# Laravel Warden

Schema-based authorization for Laravel that compiles a small, human-readable
rule language **directly into SQL** — so "what can this user do?" and
"which rows can this user touch?" are answered by the database in a single query,
not by loading records and looping in PHP.

```text
if is_self or (is_manager and same_department)
they can view, update
they cannot delete
```

That block is a real, complete Warden rule. Warden turns it into a `WHERE`
clause. This README explains the problem Warden solves, the language above in
full detail, and exactly how you hand rules to the library.

---

## Table of contents

- [The problem](#the-problem)
- [How Warden thinks about authorization](#how-warden-thinks-about-authorization)
- [Installation](#installation)
- [A complete example](#a-complete-example)
- [Schemas: the vocabulary of a resource](#schemas-the-vocabulary-of-a-resource)
  - [Abilities](#abilities)
  - [Conditions](#conditions)
  - [Targeted vs. no-target conditions](#targeted-vs-no-target-conditions)
  - [Conditions with parameters](#conditions-with-parameters)
- [The rule language](#the-rule-language)
  - [Anatomy of a rule](#anatomy-of-a-rule)
  - [`can` and `cannot`](#can-and-cannot)
  - [Conditions and boolean logic](#conditions-and-boolean-logic)
  - [Operator precedence](#operator-precedence)
  - [Wildcards](#wildcards)
  - [Passing arguments to conditions](#passing-arguments-to-conditions)
  - [Whitespace, multiple rules, and reserved words](#whitespace-multiple-rules-and-reserved-words)
  - [Formal grammar](#formal-grammar)
  - [Syntax errors](#syntax-errors)
- [Providing rules to Warden](#providing-rules-to-warden)
  - [The `RuleResolver`](#the-ruleresolver)
  - [Building a rule set](#building-a-rule-set)
  - [Building rules programmatically](#building-rules-programmatically)
  - [Implicit rules](#implicit-rules)
  - [Registering the resolver](#registering-the-resolver)
- [Checking access](#checking-access)
  - [On the model](#on-the-model)
  - [Filtering queries](#filtering-queries)
  - [Per-row abilities](#per-row-abilities)
  - [Capability (no-target) checks](#capability-no-target-checks)
  - [Match modes](#match-modes)
  - [Route middleware](#route-middleware)
- [How it compiles to SQL](#how-it-compiles-to-sql)
- [Testing](#testing)
- [API cheat sheet](#api-cheat-sheet)

---

## The problem

Say the rule is: *a user can update a timesheet if it's their own, or if they
manage the department it belongs to — but never once it's locked (unless they're
an admin).*

With a **Laravel Policy** you write that once, for a single object:

```php
class TimesheetPolicy
{
    public function update(User $user, Timesheet $timesheet): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($timesheet->locked) {
            return false;
        }

        return $timesheet->user_id === $user->id
            || $user->managedDepartmentIds()->contains($timesheet->department_id);
    }
}
```

That works for `$user->can('update', $timesheet)`. Now watch what happens with the
two questions every real screen actually asks.

**"Which timesheets can this user update?"** The policy can't answer it — it needs
an object. So you either fetch everything and filter in PHP:

```php
// Loads the whole table into memory. Pagination is now impossible.
$editable = Timesheet::all()->filter(fn ($t) => $user->can('update', $t));
```

…or you re-implement the policy a second time as a query scope:

```php
Timesheet::query()
    ->when(! $user->is_admin, function ($q) use ($user) {
        $q->where('locked', false)
          ->where(fn ($q) => $q
              ->where('user_id', $user->id)
              ->orWhereIn('department_id', $user->managedDepartmentIds()));
    })
    ->paginate();
```

Now the *same* rule lives in two places, in two different shapes, and they will
drift the first time someone edits one and forgets the other.

**"What can this user do with each row on this page?"** Your table has view /
update / delete / approve buttons. So, per page:

```php
$rows = $timesheets->map(fn ($t) => [
    'timesheet' => $t,
    'can' => [
        'view'    => $user->can('view', $t),
        'update'  => $user->can('update', $t),
        'delete'  => $user->can('delete', $t),
        'approve' => $user->can('approve', $t),
    ],
]);
// 50 rows × 4 abilities = 200 policy evaluations, each possibly hitting the DB.
```

A **flat permission package** (e.g. spatie/laravel-permission) doesn't help here —
its permissions are global strings:

```php
$user->givePermissionTo('update timesheets');
$user->can('update timesheets'); // true or false, for ALL timesheets
```

There's no room in `'update timesheets'` for *"their own"*, *"in a department they
manage"*, or *"unless locked."* The moment a permission is conditional on the
record, you're back to writing a Policy — and back to both problems above.

And in every one of these, **the rule is code**. Want managers to approve
timesheets in their department? That's a deploy. You can't store it per role or
per tenant, can't let an admin screen define it, can't audit it as data.

### The same thing in Warden

Write the rule once, as data:

```text
if is_self or manages_department they can update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

(Note how the "unless they're an admin" exception is just `and not is_admin` on
the deny — the whole rule is right there, readable, in three lines.)

Warden compiles it to SQL, so one rule set answers all three questions —
consistently, because there's only one source of truth:

```php
// "Which can they update?"  -> a WHERE clause, paginates fine
Timesheet::query()->hasAbility('update')->paginate();

// "What can they do to each row?"  -> one computed column, one query
Timesheet::query()->selectAbilities()->get();   // each row ->abilities = ['view','update']

// "Can they update this one?"  -> a scoped EXISTS
Timesheet::userHasAbilities('update', $timesheet);
```

The rest of this README is how that works.

---

## How Warden thinks about authorization

Warden splits authorization into three separate things. Keeping them separate is
the whole idea:

| Piece | What it is | Who writes it |
|---|---|---|
| **Schema** | The *vocabulary* for one resource: the abilities that exist (`view`, `approve`, …) and the conditions a rule may test (`is_self`, `is_manager`, …). Conditions know how to emit SQL. | You, in a PHP class |
| **Rules** | The *policy itself*, written in Warden's rule language as a plain string (e.g. `if is_self they can view`). Rules reference the schema's vocabulary. | Stored as data — a DB table, config, JWT claims, wherever |
| **Resolver** | The glue that, at request time, produces the rules that apply to *this* user for *this* resource. | You, one small class |

A **schema is not a policy.** It doesn't decide anything — it only declares what
words the language may use. The actual decisions live in the rules, which your
**resolver** supplies. Warden compiles those rules, validated against the schema,
into SQL.

```text
       your data (roles, grants)                    request-time
                │                                         │
                ▼                                         ▼
        RuleResolver ──▶ WardenRuleSet ──▶ RuleSetCompiler ──▶ SQL WHERE / column
                                     ▲                    │
                                     │                    │ validated against
                              WardenSchema ───────────────┘
                          (abilities + conditions)
```

---

## Installation

```bash
composer require patrickhanna/laravel-warden
```

The service provider auto-registers. Publish the config if you want to edit it in
place:

```bash
php artisan vendor:publish --tag=warden-config
```

Requirements: PHP 8.2+, Laravel 11 or 12. Supported drivers for the SQL Warden
generates: PostgreSQL, MySQL/MariaDB, and SQLite.

---

## A complete example

The four pieces end-to-end. Read the rest of the README for the detail behind
each.

**1. The schema** — declares the vocabulary for timesheets:

```php
namespace App\Warden;

use App\Models\Timesheet;
use Illuminate\Contracts\Database\Query\Builder;
use Warden\Ability;
use Warden\GlobalCondition;
use Warden\Schema\Conditions\GlobalConditionContext;
use Warden\Schema\Conditions\TargetedConditionContext;
use Warden\Schema\WardenSchema;
use Warden\TargetedCondition;

class TimesheetSchema extends WardenSchema
{
    public const model = Timesheet::class;

    #[Ability] public const VIEW    = 'view';
    #[Ability] public const UPDATE  = 'update';
    #[Ability] public const DELETE  = 'delete';
    #[Ability] public const APPROVE = 'approve';

    // Targeted: narrows WHICH timesheet rows the user matches.
    #[TargetedCondition]
    public function isSelf(TargetedConditionContext $c): Builder
    {
        return $c->query->whereRaw('timesheets.user_id = ?', [$c->user->getAuthIdentifier()]);
    }

    #[TargetedCondition]
    public function inDepartment(TargetedConditionContext $c): Builder
    {
        return $c->query->whereIn('timesheets.department_id', $c->arguments);
    }

    // No-target: a plain yes/no about the user, independent of any row.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

**2. The rules** — as data. Here inline; in practice from your DB:

```text
if is_self they can view, update, delete
if in_department(?, ?) they can view, approve
if is_admin they can *
```

**3. The resolver** — hands those rules to Warden for the current user:

```php
namespace App\Warden;

use Warden\RuleResolutionContext;
use Warden\RuleResolver;
use Warden\RuleSyntaxTree\WardenRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WardenRuleSet
    {
        // Look up the raw rule string + any binding values for this user/resource.
        [$syntax, $bindings] = MyRuleStore::for(
            user: $context->user,
            resource: $context->schemaKey, // 'timesheets'
        );

        return WardenRuleSet::fromSyntax($context->schemaKey, $syntax, $bindings);
    }
}
```

**4. Wire it up** (`config/warden.php`) and use it:

```php
'rule_resolver' => App\Warden\DatabaseRuleResolver::class,
'schemas' => [App\Warden\TimesheetSchema::class],
```

```php
// Which timesheets can the current user update? (one SQL query)
$editable = Timesheet::query()->hasAbility('update')->get();

// Can this user approve this specific timesheet?
if (Timesheet::userHasAbilities('approve', $timesheet)) { /* ... */ }

// Render buttons: attach the per-row ability list.
$rows = Timesheet::query()->selectAbilities()->get();
$rows->first()->abilities; // e.g. ['view', 'update']
```

---

## Schemas: the vocabulary of a resource

A schema is an `abstract class WardenSchema` subclass, one per resource. It
declares two things: the **abilities** that exist, and the **conditions** a rule
may test. It is registered against a model via the `model` constant.

```php
class TimesheetSchema extends WardenSchema
{
    public const model = Timesheet::class;   // the Eloquent model this governs
    // public const schemaKey = 'timesheets'; // optional override
}
```

The **schema key** (the resource's identifier in rules and lookups) is
derived from the model's table name by default (`timesheets`). Override it with
the `schemaKey` constant. A schema may also have **no model**
(`public const model = ''`) — a "capability" schema for things like `settings`
that only answer no-target checks (see [capability checks](#capability-no-target-checks)).

### Abilities

Abilities are the verbs a rule can grant or deny. Declare each as a class
constant marked `#[Ability]`. The constant's **value** is the ability name used
in rules; the constant's *name* is irrelevant to Warden.

```php
#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
```

```php
TimesheetSchema::declaredAbilities(); // ['view', 'approve', ...]
```

A rule that names an ability the schema doesn't declare is rejected at compile
time (see [validation](#how-it-compiles-to-sql)). Warden ships a
`Warden\StandardAbilities` helper with common names (`VIEW`, `CREATE`, `UPDATE`,
`DELETE`, `ARCHIVE`) if you want a shared vocabulary.

### Conditions

Conditions are the predicates a rule may test in its `if`. Each is a public
method marked with `#[TargetedCondition]` or `#[GlobalCondition]`. The condition
**name** used in rules is derived from the method name by snake-casing it
(`isSelf` → `is_self`). You can override it: `#[TargetedCondition('is_owner')]`.

Every condition method takes a **single context object** and returns `Builder`
(mutated) or, for a global condition, a `bool`. The context carries the current
user, the query builder, the DSL `arguments`, and — for targeted conditions —
the `targetSqlId`.

A condition's one job is to **emit SQL**. There is no in-memory evaluation path —
even a single-object check runs as a scoped query. This keeps a condition's
behavior identical whether you're filtering a list or checking one row.

### Targeted vs. global conditions

The distinction is: *does this predicate talk about a specific row?*

- **`#[TargetedCondition]`** — the predicate constrains *which rows* match. Its
  context is a `TargetedConditionContext` carrying `targetSqlId`, the qualified
  primary-key SQL id of the entity (`timesheets.id`). Mutate `$c->query` to add
  the `WHERE` fragment:

  ```php
  #[TargetedCondition]
  public function isSelf(TargetedConditionContext $c): Builder
  {
      // $c->targetSqlId === "timesheets.id" (the correlated row under test)
      return $c->query->whereRaw('timesheets.user_id = ?', [$c->user->getAuthIdentifier()]);
  }
  ```

  Your predicate may reference any column of the entity's table; it is evaluated
  correlated to the row under test.

- **`#[GlobalCondition]`** — the predicate is about the *user or the world*, not a
  row (e.g. "is this user an admin?", "is this tenant on the pro plan?"). Its
  context is a `GlobalConditionContext` (no `targetSqlId`). It may mutate
  `$c->query` like a targeted condition, or simply **return a `bool`**:

  ```php
  #[GlobalCondition]
  public function isAdmin(GlobalConditionContext $c): bool
  {
      return (bool) $c->user->is_admin;   // true grants outright, false grants nothing
  }
  ```

Why the split matters: some checks (**capability checks** and
`getAbilitiesWithoutTarget`) run with *no row*. In that context a targeted
condition can't be evaluated, so Warden treats it as **false** (and therefore
`not <targeted>` as **true**). Global conditions still evaluate normally.

> **Values are always bound.** Whatever you pass into `whereRaw`, `whereIn`,
> etc. becomes a bound parameter. Never interpolate a value into the SQL string
> — conditions run against user- and rule-supplied data.

### Conditions with arguments

A condition can take arguments from the rule (`in_department('sales')`). The
resolved arguments arrive on the context as **`$c->arguments`**, in order:

```php
#[TargetedCondition]
public function inDepartment(TargetedConditionContext $c): Builder
{
    // in_department('sales', 'eng')  ->  $c->arguments === ['sales', 'eng']
    return $c->query->whereIn('timesheets.department_id', $c->arguments);
}
```

A condition that ignores arguments simply never reads `$c->arguments`.

---

## The rule language

This is the heart of Warden. Rules are written as a plain string. You'll
typically store these strings (per role, per user, per tenant) and load them in
your resolver.

### Anatomy of a rule

A rule is an optional `if <expression>` followed by one or more `they can` /
`they cannot` clauses:

```text
if is_self
they can view, update
they cannot delete
```

- **`if <expression>`** — optional. When present, the clauses only apply where
  the expression holds. When omitted, the rule is **unconditional** (always
  applies).
- **`they can <abilities>`** — grants the listed abilities.
- **`they cannot <abilities>`** — denies the listed abilities.

Abilities are comma-separated. A rule may have any mix of `can` and `cannot`
clauses.

### `can` and `cannot`

Warden combines grants and denials with **deny-overrides**. For a given ability,
the compiled predicate is:

```text
( any `can` rule for it matches )  AND  ( no `cannot` rule for it matches )
```

Concretely:

- **A `cannot` is an absolute veto.** `they cannot delete` compiles to "and *not*
  the delete rule's condition." An **unconditional** `they cannot delete` means
  *no one can ever delete*, full stop — no `can` rule can bring it back.
- **An ability with no `can` rule is denied.** Silence is not permission.
- **An unconditional `they can view` grants view to every row.**

```text
they can view                 # everyone can view
if is_locked
they cannot update, delete    # ...but locked rows can never be updated or deleted,
                              #    even if another rule grants update
```

Rule *order does not matter* — the deny-overrides combination is commutative.

### Conditions and boolean logic

The `if` expression is a boolean combination of conditions:

```text
if is_self or is_manager
if is_self and not is_locked
if is_manager and (in_department('sales') or in_department('eng'))
```

- **`and`**, **`or`** — binary operators.
- **`not`** — negation. `!` is an accepted synonym (`!is_locked` ≡ `not is_locked`).
  `not` is the canonical spelling.
- **Parentheses** group sub-expressions.

Each bare name (`is_self`, `is_manager`) is a condition declared on the schema.

### Operator precedence

From tightest to loosest binding: **`not` / `!`  >  `and`  >  `or`**. Parentheses
override. So:

```text
if is_self or not is_manager and is_owner
```

parses as `is_self OR ((NOT is_manager) AND is_owner)`. When in doubt,
parenthesize. (`&&` and `||` are **not** supported — use `and` / `or`.)

### Wildcards

`*` stands for **every ability the schema declares**, on both sides:

```text
if is_admin
they can *              # grant every ability

if is_suspended
they cannot *           # deny every ability (a blanket lockout that wins)
```

`they cannot *` combined with deny-overrides is the idiomatic "kill switch."

### Passing arguments to conditions

A condition can take arguments in three ways: **inline literals**, **named
bindings**, and **positional bindings**.

**Inline literals** are written directly in the rule. Supported literal types:
`string` (single-quoted), `int`, `float`, `bool`, `null`.

```text
if in_department('sales', 'eng') they can view
if seen_recently(30, true) they can view
```

Strings use single quotes; escape a quote or backslash with `\'` and `\\`.
Lists and other complex values **cannot** be written inline — pass them via a
binding.

**Named bindings** (`:name`) are placeholders filled from a bindings array. The
*name* is what matters: a binding may be reused any number of times, appear
anywhere in the string (even across rules), and the array order is irrelevant.

```php
WardenRuleSet::fromSyntax('timesheets', <<<'RULES'
    if is_specific_user(:uid) they can view
    if delegated_to(:uid) they can approve
    RULES,
    ['uid' => $currentUserId],   // one value, used twice
);
```

**Positional bindings** (`?`) are filled left-to-right across the *entire*
string from a flat array:

```php
WardenRuleSet::fromSyntax('timesheets',
    'if in_department(?, ?) they can view',
    ['sales', 'eng'],            // ? ? -> 'sales', 'eng'
);
```

Rules for bindings — all enforced at parse time:

- **A binding value may be any PHP value** — string, int, array, an object,
  anything. (Only *inline* literals are restricted to scalars.) Your condition
  receives it verbatim in `$parameters`.
- **You may not mix** named and positional bindings in one parse.
- **Every placeholder must have a value**, and **every provided value must be
  used**. A missing binding, an unused binding, or a positional count mismatch is
  an error.

### Whitespace, multiple rules, and reserved words

- **Whitespace is insignificant.** Newlines are cosmetic; an entire rule set can
  be one line. These are identical:

  ```text
  if is_self they can view if is_manager they can approve
  ```
  ```text
  if is_self
  they can view

  if is_manager
  they can approve
  ```

- **`if` starts a new rule.** Every `if` begins a new rule; `they can/cannot`
  clauses attach to the most recent `if` above them. Clauses before any `if` form
  a single leading unconditional rule.

- **Reserved words** — `if`, `they`, `can`, `cannot`, `and`, `or`, `not` — cannot
  be used as an *exact* condition or ability name. A name may *contain* or *start
  with* one, though: `canonical`, `cannot_publish`, `is_and_something` are all
  fine.

- **Identifiers** (condition, ability, and binding names) match
  `[A-Za-z_][A-Za-z0-9_-]*`: they start with a letter or underscore and may
  contain letters, digits, underscores, and dashes. No dots.

### Formal grammar

```ebnf
ruleset   = clause* ( "if" expr clause+ )* ;
clause    = "they" ( "can" | "cannot" ) ability ( "," ability )* ;
ability   = IDENTIFIER | "*" ;
expr      = or ;
or        = and ( "or" and )* ;
and       = not ( "and" not )* ;
not       = ( "not" | "!" ) not | primary ;
primary   = "(" expr ")" | condition ;
condition = IDENTIFIER ( "(" ( arg ( "," arg )* )? ")" )? ;
arg       = STRING | INT | FLOAT | BOOL | NULL | NAMED_BINDING | POSITIONAL ;
```

### Syntax errors

Malformed syntax throws `Warden\RuleSyntaxTree\WardenSyntaxException` eagerly,
with the line, column, and a caret pointing at the offending token — debuggable
even when the whole rule set is one line:

```
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Name validation (does this ability/condition actually exist on the schema?)
happens later, at **compile time**, when a rule set is compiled against a schema
— also as a hard error.

---

## Providing rules to Warden

Rules are data. Warden never invents them; it asks *your* resolver for them.

### The `RuleResolver`

Implement one interface. Given a context (the user, the resource's schema key, the
schema class, and the model class), return the `WardenRuleSet` that governs this
user's access to that resource.

```php
use Warden\RuleResolutionContext;
use Warden\RuleResolver;
use Warden\RuleSyntaxTree\WardenRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WardenRuleSet
    {
        // $context->user               — the Authenticatable being checked
        // $context->schemaKey — e.g. 'timesheets'
        // $context->schema             — the schema class string
        // $context->model              — the model class string, or null

        $grants = DB::table('role_permissions')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)
            ->pluck('rule');                    // ['if is_self they can view', ...]

        return WardenRuleSet::fromSyntax(
            $context->schemaKey,
            $grants->implode("\n"),             // rules concatenate freely
        );
    }
}
```

The resolver is where *your* access-control model meets Warden. Store rule strings in
a table, compose them from role flags, read them from JWT claims — whatever fits.
Warden only cares that you return a `WardenRuleSet`.

### Building a rule set

Three ways to construct a `WardenRuleSet`:

**From syntax** (parse a string, resolving bindings inline):

```php
WardenRuleSet::fromSyntax('timesheets', 'if is_self they can view', $bindings = []);
```

**From already-parsed rules** — build individual `WardenRule`s and compose them.
`fromRules` takes a variadic list *or* a single array, and accepts no bindings
(the rules are already resolved):

```php
use Warden\RuleSyntaxTree\WardenRule;

$own      = WardenRule::fromSyntax('if is_self they can view, update');
$noDelete = WardenRule::fromSyntax('they cannot delete');

WardenRuleSet::fromRules('timesheets', $own, $noDelete);
WardenRuleSet::fromRules('timesheets', [$own, $noDelete]); // equivalent
```

**Directly with the parser**, if you want the parsed rules without a rule set:

```php
use Warden\RuleSyntaxTree\Parsing\WardenParser;

$rules = WardenParser::parse('if is_self they can view', $bindings = []); // WardenRule[]
$one   = WardenParser::parseSingleRule('they cannot delete');            // WardenRule
```

### Building rules programmatically

When a rule's shape depends on runtime data — a list of department ids, a
feature flag, values that don't belong in a string — a fluent builder is often
clearer than assembling DSL text. `WardenRule::build()` returns a builder that
produces the **same AST** the parser does, so a built rule flows through the
identical validation and compilation. Nothing is ever serialized to a string,
so arbitrary PHP values in condition parameters survive untouched.

```php
use Warden\RuleSyntaxTree\WardenRule;

$rule = WardenRule::build()
    ->if('is_self')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete')
    ->toRule();
```

That builds the same rule as:

```
if is_self or (is_manager and in_region) they can view, update; they cannot delete
```

**Conditions.** Each connective has a plain and a negated form, mirroring
Laravel's `where`/`orWhere`/`whereNot`:

| Method | DSL equivalent |
| --- | --- |
| `if` / `andIf` | `and` (both are aliases; the first term's connective is ignored) |
| `orIf` | `or` |
| `ifNot` / `andIfNot` | `and not` |
| `orIfNot` | `or not` |

Each takes a condition name (with optional parameters) **or** a closure:

```php
->if('in_department', ['sales', 'eng'])   // condition with parameters
->orIf(fn ($c) => $c->if('a')->orIf('b')) // closure = a parenthesized group
```

A **closure is a parenthesized group**. It receives a bare condition builder —
it has the `if`/`orIf`/… methods but no `theyCan`/`theyCannot`, because a group
is only ever a condition, never a whole rule.

**Clauses.** `theyCan(...$abilities)` and `theyCannot(...$abilities)` are
variadic and additive. A rule needs at least one clause: `toRule()` throws if
you call neither, exactly as the DSL rejects a bare `if` with no `they can` /
`they cannot` line.

**Precedence is identical to the DSL** — `not` > `and` > `or` — so the two
front-ends produce byte-for-byte identical trees. `->if('a')->andIf('b')->orIf('c')`
is `(a and b) or c`, not `a and (b or c)`.

**Composing dynamically.** The builder shines when the tree is data-driven.
Fold a list inside a group, or branch with `when()`:

```php
$rule = WardenRule::build()
    ->if('is_self')
    ->orIf(function ($c) use ($departmentIds) {
        foreach ($departmentIds as $id) {
            $c->orIf('in_department', [$id]);
        }
    })
    ->when($includeManagers, fn ($c) => $c->orIf('is_manager'))
    ->theyCan('view')
    ->toRule();
```

An empty group folds to `false`, so it contributes nothing to an `or` and vetoes
an `and` — folding an empty list is a safe no-op.

**Splicing in DSL text.** `ifRaw()` / `orIfRaw()` parse a DSL fragment and splice
it in as one group — author the readable part as text, compose the rest
structurally:

```php
->ifRaw('is_admin or is_owner', $bindings = [])->andIf('in_region')
```

**Dropping into a rule set.** `fromRules` accepts builders directly (it finalizes
each via `toRule()`), so you don't have to call `toRule()` yourself:

```php
WardenRuleSet::fromRules(
    'timesheets',
    WardenRule::build()->if('is_self')->theyCan('view', 'update'),
    WardenRule::build()->theyCannot('delete'),
);
```

### Implicit rules

A schema can declare rules that are **always in force**, regardless of what the
resolver returns, by overriding `implicitRules()`. They're merged into every
resolved rule set before compilation, so they're validated and obey
deny-overrides exactly like resolver rules. Ideal for baseline guarantees — an
admin escape hatch, or a universal lockout:

```php
use Warden\RuleSyntaxTree\WardenRule;

class TimesheetSchema extends WardenSchema
{
    protected function implicitRules(): array
    {
        return [
            WardenRule::fromSyntax('if is_admin they can *'),
            WardenRule::fromSyntax('if is_suspended they cannot *'),
        ];
    }
}
```

Because deny-overrides is order-independent, an implicit `cannot` beats any
resolver-supplied `can`.

### Registering the resolver

Warden ships **no** default resolver — you must configure one in
`config/warden.php`, plus the list of schemas Warden should know about:

```php
return [
    'rule_resolver' => App\Warden\DatabaseRuleResolver::class,

    'schemas' => [
        App\Warden\TimesheetSchema::class,
        App\Warden\ProjectSchema::class,
    ],
];
```

---

## Checking access

Once the schema, resolver, and rules are in place, you never touch the compiler
directly. You ask questions through the model, query scopes, the schema's static
helpers, or middleware.

### On the model

Add the `HasWardenSchema` trait and point it at the schema:

```php
use Illuminate\Database\Eloquent\Model;
use Warden\HasWardenSchema;

class Timesheet extends Model
{
    use HasWardenSchema;

    public function wardenSchema(): string
    {
        return App\Warden\TimesheetSchema::class;
    }
}
```

That unlocks:

```php
// Boolean checks (run as a scoped EXISTS query):
Timesheet::userHasAbilities('update', $timesheet);           // for a model instance
Timesheet::userHasAbilities('update', $timesheetId);         // for a key
Timesheet::userHasAbilities(['view', 'update'], $timesheet); // several at once
Timesheet::userHasAbilities('create');                       // no-target / capability

// The ability list for one record:
Timesheet::getUserAbilities($timesheet);                     // ['view', 'update']

// Attach abilities onto a loaded model:
$timesheet->loadAbilities();                                 // sets $timesheet->abilities
```

Each accepts an optional `$user` (defaults to `auth()->user()`) and, for
`userHasAbilities`, an `AbilityMatchMode`.

### Filtering queries

The `hasAbility` scope restricts a query to the rows the user may act on — the
"which records?" question, answered in SQL:

```php
// Timesheets the current user can update:
Timesheet::query()->hasAbility('update')->paginate();

// Rows they can BOTH view and approve (see match modes):
Timesheet::query()->hasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ALL)->get();

// For a specific user:
Timesheet::query()->hasAbility('delete', $user)->get();
```

### Per-row abilities

The `selectAbilities` scope attaches a computed `abilities` column — a JSON array
of what the user can do to *that* row — so your UI can render controls without
N extra checks:

```php
$rows = Timesheet::query()->selectAbilities()->get();

$rows->first()->abilities; // ['view', 'update']
```

On a list endpoint you often only care about a subset (say, just `update` to show
an Edit button). Narrowing it is a real cost saving — the attached subquery grows
one branch per ability:

```php
Timesheet::query()->selectAbilities(onlyAbilities: ['update'])->get();
```

### Capability (no-target) checks

Not every check is about a row. "Can this user *create* timesheets?" or "can
they access *settings*?" have no target. Pass `null` as the target (or omit it):

```php
Timesheet::userHasAbilities('create');                 // target defaults to null
TimesheetSchema::getUserAbilities();                   // all no-target abilities
```

For section-level capabilities with no model at all, define a schema with
`public const model = ''` and only `#[GlobalCondition]` conditions. In a
no-target check, targeted conditions are treated as false, so only global
logic contributes.

### Match modes

When you check several abilities at once, `AbilityMatchMode` decides how they
combine:

- **`AbilityMatchMode::ALL`** (default) — the row/user must satisfy *every* listed
  ability.
- **`AbilityMatchMode::ANY`** — *any* one is enough.

```php
use Warden\AbilityMatchMode;

Timesheet::query()->hasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)->get();
```

### Route middleware

Warden registers a `warden` route middleware. Build the middleware string with
`WardenMiddleware`:

```php
use Warden\WardenMiddleware;

// Capability (no-target) — gate a create route by schema key:
Route::post('/timesheets', ...)->middleware(WardenMiddleware::canCreate('timesheets'));

// Targeted — gate by a route-model-bound parameter:
Route::get('/timesheets/{timesheet}', ...)
    ->middleware(WardenMiddleware::string('timesheet', 'view'));

// Group helper:
WardenMiddleware::guard('timesheets', 'view', function () {
    Route::get('/timesheets', ...);
});
```

There are `canView`, `canCreate`, `canUpdate`, `canDelete`, `canArchive`, and
`canManage` shortcuts. Under the hood the middleware resolves the target to a
schema (by schema key or by the route-bound model's class) and calls
`userHasAbilities`, aborting `403` on failure.

---

## How it compiles to SQL

You don't need this section to use Warden, but it explains *why* the semantics
are what they are.

For each requested ability, the compiler assembles one predicate from all the
rules that mention it (or `*`):

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

with these hard edges:

- An **unconditional `cannot`** → `AND NOT(true)` → `1 = 0`: the ability is
  impossible.
- **No `can` rule** for the ability → `1 = 0`: denied by default.
- An **unconditional `can`** → an always-true `1 = 1` term.

Every condition leaf is wrapped as an **`EXISTS`** subquery, which makes it a
strict boolean: a condition that touches a `NULL` column yields `false`, not SQL's
"unknown," and negation via `NOT EXISTS` is exact. This is why `not`/`cannot`
behave predictably — no three-valued-logic surprises leak into your
authorization results. Boolean structure (`and`/`or`/`not`) becomes nested
`WHERE` groups, with negation pushed to the leaves via De Morgan.

Row filtering applies these predicates to your query's `WHERE`; per-row ability
selection runs them as correlated subqueries producing the JSON column. Because
everything is one compiler, the "which rows?", "what can they do?", and "can
they?" questions can never disagree.

Compilation validates every ability and condition name against the schema; an
unknown name is a hard error, so a typo in a stored rule fails loudly rather than
silently granting or denying.

---

## Testing

Warden's own suite drives real SQLite and asserts on rows and ability lists
rather than SQL strings. The same approach works for your schemas: register a
fake resolver that returns a fixed `WardenRuleSet`, seed a table, and assert what
comes back.

```php
app()->instance(RuleResolver::class, new class implements RuleResolver {
    public function resolve(RuleResolutionContext $context): WardenRuleSet
    {
        return WardenRuleSet::fromSyntax($context->schemaKey, 'if is_self they can view');
    }
});

$visible = Timesheet::query()->hasAbility('view', $user)->pluck('id');
expect($visible)->toContain($ownTimesheet->id)->not->toContain($othersTimesheet->id);
```

---

## API cheat sheet

**Define a schema** — `extends Warden\Schema\WardenSchema`
- `const model` — managed Eloquent model (or `''` for a capability schema)
- `const schemaKey` — optional schema-key override
- `#[Ability] const X = '...'` — declare an ability
- `#[TargetedCondition]` / `#[GlobalCondition]` methods — declare conditions
- `protected function implicitRules(): array` — always-on rules

**Build rules**
- `WardenRuleSet::fromSyntax(string $entity, string $syntax, array $bindings = [])`
- `WardenRuleSet::fromRules(string $entity, WardenRule|WardenRuleBuilder|array ...$rules)`
- `WardenRule::fromSyntax(string $syntax, array $bindings = [])`
- `WardenParser::parse(string $source, array $bindings = []): WardenRule[]`
- `WardenParser::parseSingleRule(string $source, array $bindings = []): WardenRule`
- `WardenRule::build()` — fluent builder: `->if/andIf/orIf/ifNot/…`, `->theyCan/theyCannot`, `->toRule()`

**Provide rules** — implement `Warden\RuleResolver`
- `resolve(RuleResolutionContext $context): WardenRuleSet`
- context: `->user`, `->schemaKey`, `->schema`, `->model`
- register in `config/warden.php` → `rule_resolver`, `schemas`

**Check access** — `use Warden\HasWardenSchema` on the model
- `Model::userHasAbilities($abilities, $target = null, $user = null, $matchMode = ALL): bool`
- `Model::getUserAbilities($target = null, $user = null): array`
- `->hasAbility($abilities, $user = null, $matchMode = ALL)` — query scope
- `->selectAbilities($user = null, $key = 'abilities', ?array $onlyAbilities = null)` — query scope
- `$model->loadAbilities($user = null)` — attach the ability list to an instance
- `Warden\AbilityMatchMode::ALL | ANY`

**Middleware** — `Warden\WardenMiddleware`
- `::string($target, $abilities, $matchMode = ALL)`
- `::guard($target, $abilities, Closure $routes, $matchMode = ALL)`
- `::canView / canCreate / canUpdate / canDelete / canArchive / canManage($target, ?Closure)`

## License

MIT.
