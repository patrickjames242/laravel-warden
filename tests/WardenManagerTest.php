<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warden\Facades\Warden;
use Warden\WardenManager;

beforeEach(function () {
    useWardenPolicies([WardenTestPolicy::class]);
});

it('validates a single permission string and returns it unchanged', function () {
    expect(Warden::validatePermissionStrings('course_sections.view'))->toBe('course_sections.view');
});

it('validates an array of permission strings and returns it unchanged', function () {
    $permissions = [
        'course_sections.view',
        'course_sections.is_teacher.update',
        'course_sections.*',
        'course_sections.is_teacher.*',
    ];

    expect(Warden::validatePermissionStrings($permissions))->toBe($permissions);
});

it('rejects permission strings with an invalid format', function (string $permission) {
    expect(fn () => Warden::validatePermissionStrings($permission))
        ->toThrow(InvalidArgumentException::class, 'is invalid');
})->with([
    'course_sections',
    'course_sections.is_teacher.view.extra',
]);

it('rejects an invalid permission base name', function () {
    expect(fn () => Warden::validatePermissionStrings('widgets.view'))
        ->toThrow(InvalidArgumentException::class, 'invalid permission base name');
});

it('rejects an invalid condition', function () {
    expect(fn () => Warden::validatePermissionStrings('course_sections.is_wizard.view'))
        ->toThrow(InvalidArgumentException::class, 'invalid condition');
});

it('rejects an invalid ability', function () {
    expect(fn () => Warden::validatePermissionStrings('course_sections.destroy'))
        ->toThrow(InvalidArgumentException::class, 'invalid ability');
});

it('resolves the policy for a model class', function () {
    expect(Warden::getPolicyForModelClass(WardenTestModel::class))->toBe(WardenTestPolicy::class);
});

it('resolves the policy for a permission base name', function () {
    expect(Warden::getPolicyForPermissionBaseName('course_sections'))->toBe(WardenTestPolicy::class);
});

it('throws when no policy is registered for a model class', function () {
    expect(fn () => Warden::getPolicyForModelClass('App\\Models\\Nope'))
        ->toThrow(OutOfBoundsException::class, 'No Warden policy registered for model');
});

it('throws when no policy is registered for a permission base name', function () {
    expect(fn () => Warden::getPolicyForPermissionBaseName('widgets'))
        ->toThrow(OutOfBoundsException::class, 'No Warden policy registered for permission base name');
});

it('lists the registered policies', function () {
    expect(Warden::registeredPolicies())->toBe([WardenTestPolicy::class]);
});

it('throws when two policies claim the same permission base name', function () {
    expect(fn () => new WardenManager([WardenTestPolicy::class, WardenScopedModelPolicy::class]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate policy for permission base name');
});

it('builds a combined no-target abilities bag keyed by base name', function () {
    bindWardenPermissions([
        'course_sections.publish',
        'course_sections.view',
    ]);

    expect(Warden::getNoTargetAbilitiesBag(makeWardenTestUser('teacher-role'), WardenTestPolicy::class))
        ->toBe([
            'course_sections' => [
                'permission_base_name' => 'course_sections',
                'abilities' => ['publish', 'view'],
                'target' => null,
            ],
        ]);
});
