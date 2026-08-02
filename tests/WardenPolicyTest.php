<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warden\AbilityMatchMode;

function createCourseSectionsTable(): void
{
    Schema::create('course_sections', function ($table) {
        $table->string('id');
    });
}

function seedCourseSections(): void
{
    createCourseSectionsTable();

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);
}

/**
 * @return array<int, string>
 */
function filteredSectionIds(string|array $abilities, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): array
{
    return (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', $abilities, $matchMode)
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

// -- filterQuery (behavioral) -------------------------------------------------

it('matches every row for an unconditional grant', function () {
    seedCourseSections();
    bindWardenRules('they can view');

    expect(filteredSectionIds('view'))->toBe(['other-section', 'teacher:teacher-role']);
});

it('matches every row for a wildcard grant', function () {
    seedCourseSections();
    bindWardenRules('they can *');

    expect(filteredSectionIds('view'))->toBe(['other-section', 'teacher:teacher-role']);
});

it('matches only rows satisfying a targeted condition', function () {
    seedCourseSections();
    bindWardenRules('if is_teacher they can view');

    expect(filteredSectionIds('view'))->toBe(['teacher:teacher-role']);
});

it('ORs a no-target and a targeted condition', function () {
    seedCourseSections();
    bindWardenRules('if is_advisor or is_teacher they can view');

    // is_advisor is false for a teacher role, so only the teacher row matches.
    expect(filteredSectionIds('view'))->toBe(['teacher:teacher-role']);
});

it('denies all rows when no rule grants the ability', function () {
    seedCourseSections();
    bindWardenRules('if is_teacher they can update');

    expect(filteredSectionIds('view'))->toBe([]);
});

it('applies deny-overrides against an unconditional grant', function () {
    seedCourseSections();
    bindWardenRules('they can view if is_teacher they cannot view');

    expect(filteredSectionIds('view'))->toBe(['other-section']);
});

it('denies everything under an unconditional cannot', function () {
    seedCourseSections();
    bindWardenRules('they can view they cannot view');

    expect(filteredSectionIds('view'))->toBe([]);
});

it('requires every ability under ALL match mode', function () {
    seedCourseSections();
    bindWardenRules('if is_teacher they can view, update');

    expect(filteredSectionIds(['view', 'update'], AbilityMatchMode::ALL))->toBe(['teacher:teacher-role']);
});

it('keeps an unconditional grant winning in ANY match mode', function () {
    seedCourseSections();
    bindWardenRules('they can view if is_teacher they can update');

    // view is granted unconditionally, so every row passes an ANY check.
    expect(filteredSectionIds(['view', 'update'], AbilityMatchMode::ANY))
        ->toBe(['other-section', 'teacher:teacher-role']);
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

it('throws when the rule set names an undeclared ability', function () {
    bindWardenRules('they can teleport', entityName: 'course_sections');

    expect(fn () => (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [teleport] is not declared by the policy');
});

it('throws when the rule set names an undeclared condition', function () {
    bindWardenRules('if is_wizard they can view');

    expect(fn () => (new WardenTestPolicy)
        ->filterQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id', 'view'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_wizard] is not declared by the policy');
});

// -- reflection helpers -------------------------------------------------------

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

// -- selectAbilitiesInQuery (behavioral) --------------------------------------

it('computes per-row abilities as a json column', function () {
    seedCourseSections();
    bindWardenRules('they can publish if is_teacher they can view');

    $rows = (new WardenTestPolicy)
        ->selectAbilitiesInQuery(makeWardenTestUser('teacher-role'), wardenTestQuery(), 'course_sections.id')
        ->orderBy('id')
        ->get();

    $abilitiesById = $rows->mapWithKeys(fn ($row) => [$row->id => json_decode($row->abilities, true)])->all();

    expect($abilitiesById['teacher:teacher-role'])->toBe(['publish', 'view']);
    expect($abilitiesById['other-section'])->toBe(['publish']);
});

// -- no-target ability lists --------------------------------------------------

it('returns abilities the user can perform without an entity in one query', function () {
    bindWardenRules('they can publish, view if is_advisor they can create');

    $policy = new WardenTestPolicy;
    $user = makeWardenTestUser('advisor');

    expect($policy->getAbilitiesWithoutEntity($user))->toBe(['create', 'publish', 'view']);
    expect($policy->getAbilitiesWithoutEntity($user, ['create', 'publish'], AbilityMatchMode::ALL))
        ->toBe(['create', 'publish']);
    expect($policy->getAbilitiesWithoutEntity($user, ['create', 'publish', 'archive'], AbilityMatchMode::ALL))
        ->toBe([]);
});

it('grants an ability when a no-target boolean condition evaluates true, denies when false', function () {
    bindWardenRules('if is_super_user they can view');

    $policy = new WardenBooleanConditionPolicy;

    expect($policy->getAbilitiesWithoutEntity(makeWardenTestUser('super-role')))->toBe(['view']);
    expect($policy->getAbilitiesWithoutEntity(makeWardenTestUser('other-role')))->toBe([]);
});

// -- static entry points ------------------------------------------------------

it('checks abilities statically for an entity instance, id, or no target', function () {
    seedCourseSections();
    bindWardenRules('they can publish if is_teacher they can view, update');

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
    seedCourseSections();
    bindWardenRules('they can publish if is_teacher they can view');

    $user = makeWardenTestUser('teacher-role');

    expect(WardenScopedModel::userHasAbilities('view', 'teacher:teacher-role', $user))->toBeTrue();
    expect(WardenScopedModel::getUserAbilities(null, $user))->toBe(['publish']);
});

it('throws when the model returns a policy for a different host model', function () {
    expect(fn () => WardenMismatchedScopedModel::query()->hasAbility('view', makeWardenTestUser('teacher-role'))->toRawSql())
        ->toThrow(LogicException::class, 'must manage model');
});
