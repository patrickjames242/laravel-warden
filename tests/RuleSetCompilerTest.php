<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warden\RuleSyntaxTree\ConditionResolver;
use Warden\RuleSyntaxTree\RuleSetCompiler;
use Warden\RuleSyntaxTree\WardenRuleSet;

/**
 * A tiny user carrying just a role, enough for the fake conditions below.
 */
final class CompilerTestUser implements Authenticatable
{
    public function __construct(public ?string $role = null) {}

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->role; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getAuthPassword(): ?string { return null; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): ?string { return null; }
}

/**
 * Fake schema seam:
 *  - abilities: view, edit, delete, publish
 *  - is_teacher   (targeted)        : id = "teacher:{role}"
 *  - is_owner(id) (targeted, param) : id = param[0]
 *  - is_admin     (no-target bool)  : role === 'admin'
 */
final class FakeConditionResolver implements ConditionResolver
{
    private const TARGETED = ['is_teacher' => true, 'is_owner' => true, 'is_admin' => false];

    public function declaredAbilities(): array
    {
        return ['view', 'edit', 'delete', 'publish'];
    }

    public function conditionExists(string $name): bool
    {
        return array_key_exists($name, self::TARGETED);
    }

    public function conditionIsTargeted(string $name): bool
    {
        return self::TARGETED[$name] ?? false;
    }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, ?string $entitySqlId, array $parameters): Builder|bool
    {
        return match ($name) {
            'is_teacher' => $whereClause->whereRaw("{$entitySqlId} = ?", ["teacher:{$user->role}"]),
            'is_owner' => $whereClause->whereRaw("{$entitySqlId} = ?", [$parameters[0]]),
            'is_admin' => $user->role === 'admin',
            default => throw new RuntimeException("unknown condition {$name}"),
        };
    }
}

function compileDocIds(string $syntax, string $ability, ?string $role = 'role-1', array $bindings = []): array
{
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $ruleSet = WardenRuleSet::fromSyntax('docs', $syntax, $bindings);

    $query = DB::table('docs');
    $predicate = $compiler->compileAbility(new CompilerTestUser($role), $query, $ability, $ruleSet, 'docs.id');
    $query->addNestedWhereQuery($predicate);

    return $query->orderBy('id')->pluck('id')->all();
}

beforeEach(function () {
    Schema::create('docs', function ($table) {
        $table->string('id');
    });

    DB::table('docs')->insert([
        ['id' => 'teacher:role-1'],
        ['id' => 'doc-9'],
        ['id' => 'other'],
    ]);
});

it('grants every row for an unconditional can', function () {
    expect(compileDocIds('they can view', 'view'))->toBe(['doc-9', 'other', 'teacher:role-1']);
});

it('grants no row when the ability is never mentioned', function () {
    expect(compileDocIds('they can view', 'edit'))->toBe([]);
});

it('grants only rows matching a targeted condition', function () {
    expect(compileDocIds('if is_teacher they can view', 'view'))->toBe(['teacher:role-1']);
});

it('passes condition parameters through bindings', function () {
    expect(compileDocIds('if is_owner(:id) they can view', 'view', 'role-1', ['id' => 'doc-9']))
        ->toBe(['doc-9']);
});

it('ORs conditions', function () {
    expect(compileDocIds("if is_teacher or is_owner('doc-9') they can view", 'view'))
        ->toBe(['doc-9', 'teacher:role-1']);
});

it('ANDs conditions', function () {
    expect(compileDocIds("if is_teacher and is_owner('teacher:role-1') they can view", 'view'))
        ->toBe(['teacher:role-1']);
    expect(compileDocIds("if is_teacher and is_owner('doc-9') they can view", 'view'))
        ->toBe([]);
});

it('negates a targeted condition with not', function () {
    expect(compileDocIds('if not is_teacher they can view', 'view'))
        ->toBe(['doc-9', 'other']);
});

it('applies deny-overrides: a conditional cannot subtracts from a grant', function () {
    expect(compileDocIds('they can view if is_teacher they cannot view', 'view'))
        ->toBe(['doc-9', 'other']);
});

it('applies deny-overrides: an unconditional cannot denies everything', function () {
    expect(compileDocIds('they can view they cannot view', 'view'))->toBe([]);
});

it('expands a wildcard can to every declared ability', function () {
    expect(compileDocIds('if is_teacher they can *', 'edit'))->toBe(['teacher:role-1']);
    expect(compileDocIds('if is_teacher they can *', 'delete'))->toBe(['teacher:role-1']);
});

it('expands a wildcard cannot to every declared ability', function () {
    expect(compileDocIds('they can * if is_teacher they cannot *', 'edit'))
        ->toBe(['doc-9', 'other']);
});

it('resolves a no-target boolean condition', function () {
    expect(compileDocIds('if is_admin they can view', 'view', 'admin'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);
    expect(compileDocIds('if is_admin they can view', 'view', 'not-admin'))->toBe([]);
});

it('forces a targeted condition to false with no target, true under not', function () {
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $user = new CompilerTestUser('role-1');

    // No entitySqlId: is_teacher is forced false.
    $granted = WardenRuleSet::fromSyntax('docs', 'if is_teacher they can view');
    $q = DB::table('docs');
    $q->addNestedWhereQuery($compiler->compileAbility($user, $q, 'view', $granted, null));
    expect($q->count())->toBe(0);

    // not is_teacher => true, so every row.
    $negated = WardenRuleSet::fromSyntax('docs', 'if not is_teacher they can view');
    $q2 = DB::table('docs');
    $q2->addNestedWhereQuery($compiler->compileAbility($user, $q2, 'view', $negated, null));
    expect($q2->count())->toBe(3);
});

it('validates unknown ability and condition names', function () {
    $compiler = new RuleSetCompiler(new FakeConditionResolver);

    expect(fn () => $compiler->validate(WardenRuleSet::fromSyntax('docs', 'they can fly')))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly]');

    expect(fn () => $compiler->validate(WardenRuleSet::fromSyntax('docs', 'if is_wizard they can view')))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_wizard]');

    // A valid set passes silently.
    $compiler->validate(WardenRuleSet::fromSyntax('docs', 'if is_teacher they can view, edit'));
    expect(true)->toBeTrue();
});
