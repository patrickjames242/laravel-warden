<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warden\AbilityMatchMode;
use Warden\PermissionResolver;
use Warden\WardenPermission;

function createCourseSectionsTable(): void
{
    Schema::create('course_sections', function ($table) {
        $table->string('id');
    });
}

it('builds condition-only filters from the resolved permissions', function () {
    bindWardenPermissions([
        'course_sections.is_advisor.view',
        'course_sections.is_teacher.view',
    ]);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe(
        'select * from "course_sections" where(((('."'advisor' = 'teacher-role'".'))or((course_sections.id = '."'teacher:teacher-role'".'))))'
    );
});

it('matches every row with an always-true term for an unconditional grant', function () {
    bindWardenPermissions(['course_sections.view']);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe('select * from "course_sections" where((1 = 1))');
});

it('matches every row with an always-true term for a wildcard grant', function () {
    bindWardenPermissions(['course_sections.*']);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe('select * from "course_sections" where((1 = 1))');
});

it('denies all rows when no resolved permission grants the ability', function () {
    bindWardenPermissions(['course_sections.is_teacher.update']);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe('select * from "course_sections" where((1 = 0))');
});

it('builds all-match filters that require every ability group', function () {
    bindWardenPermissions([
        'course_sections.is_teacher.view',
        'course_sections.is_teacher.update',
    ]);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', ['view', 'update'], AbilityMatchMode::ALL)
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe(
        'select * from "course_sections" where((((course_sections.id = '."'teacher:teacher-role'".')))and(((course_sections.id = '."'teacher:teacher-role'".'))))'
    );
});

it('keeps the always-true term in an any-mode mix of unconditional and conditional grants', function () {
    bindWardenPermissions([
        'course_sections.view',
        'course_sections.is_teacher.update',
    ]);

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', ['view', 'update'], AbilityMatchMode::ANY)
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe(
        'select * from "course_sections" where((1 = 1)or(((course_sections.id = '."'teacher:teacher-role'".'))))'
    );
});

it('ignores resolved permissions belonging to another base name', function () {
    bindWardenPermissions([
        'other_base.*',
        'other_base.view',
    ]);

    $sql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toSql();

    expect($sql)->toBe('select * from "course_sections" where 1 = 0');
});

it('accepts resolved permissions supplied as WardenPermission instances', function () {
    app()->instance(PermissionResolver::class, new FakeWardenPermissionResolver([
        new WardenPermission('course_sections', null, 'view'),
    ]));

    $rawSql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe('select * from "course_sections" where((1 = 1))');
});

it('leaves the query unchanged when abilities are empty', function () {
    $sql = (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', [])
        ->toSql();

    expect($sql)->toBe('select * from "course_sections"');
});

it('throws when an ability is not defined on the policy', function () {
    expect(fn () => (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'destroy'))
        ->toThrow(InvalidArgumentException::class, 'Ability [destroy] is not defined on policy');
});

it('returns the full reflected list of abilities', function () {
    expect(WardenTestPolicy::getAbilities())->toBe(['create', 'publish', 'archive', 'view', 'update']);
});

it('returns targeted, no-target, and all condition keys', function () {
    expect(WardenTestPolicy::targetedConditionKeys())->toBe(['is_teacher']);
    expect(WardenTestPolicy::noTargetConditionKeys())->toBe(['is_advisor']);
    expect(WardenTestPolicy::conditionKeys())->toBe(['is_advisor', 'is_teacher']);
});

it('requires an entity sql id for targeted conditions', function () {
    expect(fn () => (new WardenTestPolicy)->applyConditionFilter(
        'is_teacher',
        makeWardenTestUser('teacher-role'),
        wardenTestQuery()
    ))->toThrow(InvalidArgumentException::class, 'requires an entity SQL id');
});

it('selects abilities in the query', function () {
    bindWardenPermissions([
        'course_sections.is_advisor.view',
        'course_sections.is_teacher.view',
    ]);

    $rawSql = (new WardenTestPolicy)
        ->selectAbilitiesInQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id')
        ->toRawSql();

    expect(normalizeWardenSql($rawSql))->toBe(
        'select *, (select coalesce(json_group_array("ability"), json_array())from(select * from(select '."'create'".' as "ability" where((1 = 0)))union all select * from(select '."'publish'".' as "ability" where((1 = 0)))union all select * from(select '."'archive'".' as "ability" where((1 = 0)))union all select * from(select '."'view'".' as "ability" where(((('."'advisor' = 'teacher-role'".'))or((course_sections.id = '."'teacher:teacher-role'".')))))union all select * from(select '."'update'".' as "ability" where((1 = 0))))as "available_abilities")as "abilities" from "course_sections"'
    );
});

it('returns abilities the user can perform without an entity in one query', function () {
    bindWardenPermissions([
        'course_sections.is_advisor.create',
        'course_sections.publish',
        'course_sections.view',
    ]);

    $policy = new WardenTestPolicy;
    $user = makeWardenTestUser('advisor');

    expect($policy->getAbilitiesWithoutEntity($user))->toBe(['create', 'publish', 'view']);
    expect($policy->getAbilitiesWithoutEntity($user, ['create', 'publish'], AbilityMatchMode::ALL))
        ->toBe(['create', 'publish']);
    expect($policy->getAbilitiesWithoutEntity($user, ['create', 'publish', 'archive'], AbilityMatchMode::ALL))
        ->toBe([]);
});

it('grants an ability when a no-target boolean condition evaluates true, denies when false', function () {
    bindWardenPermissions(['course_sections.is_super_user.view']);

    $policy = new WardenBooleanConditionPolicy;

    expect($policy->getAbilitiesWithoutEntity(makeWardenTestUser('super-role')))->toBe(['view']);
    expect($policy->getAbilitiesWithoutEntity(makeWardenTestUser('other-role')))->toBe([]);
});

it('checks abilities statically for an entity instance, id, or no target', function () {
    createCourseSectionsTable();
    bindWardenPermissions([
        'course_sections.is_teacher.view',
        'course_sections.is_teacher.update',
        'course_sections.publish',
    ]);

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);

    $user = makeWardenTestUser('teacher-role');
    $entity = new WardenTestModel;
    $entity->id = 'teacher:teacher-role';
    $entity->exists = true;

    expect(WardenTestPolicy::userHasAbilities('view', $entity, $user))->toBeTrue();
    expect(WardenTestPolicy::userHasAbilities(['view', 'update'], 'teacher:teacher-role', $user))->toBeTrue();
    expect(WardenTestPolicy::userHasAbilities('view', 'other-section', $user))->toBeFalse();
    expect(WardenTestPolicy::userHasAbilities('publish', null, $user, AbilityMatchMode::ANY))->toBeTrue();
    expect(WardenTestPolicy::userHasAbilities(['create', 'publish'], null, $user, AbilityMatchMode::ALL))->toBeFalse();
});

it('forwards static ability helpers through the model trait', function () {
    createCourseSectionsTable();
    bindWardenPermissions([
        'course_sections.is_teacher.view',
        'course_sections.publish',
    ]);

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
    ]);

    $user = makeWardenTestUser('teacher-role');

    expect(WardenScopedModel::userHasAbilities('view', 'teacher:teacher-role', $user))->toBeTrue();
    expect(WardenScopedModel::getUserAbilities(null, $user))->toBe(['publish']);
});

it('throws when the model returns a policy for a different host model', function () {
    expect(fn () => WardenMismatchedScopedModel::query()->hasAbility('view', makeWardenTestUser('teacher-role'))->toRawSql())
        ->toThrow(LogicException::class, 'must manage model');
});
