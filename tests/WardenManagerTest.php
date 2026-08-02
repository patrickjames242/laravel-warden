<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warden\Facades\Warden;
use Warden\WardenManager;

beforeEach(function () {
    useWardenPolicies([WardenTestPolicy::class]);
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
    bindWardenRules('they can publish, view');

    expect(Warden::getNoTargetAbilitiesBag(makeWardenTestUser('teacher-role'), WardenTestPolicy::class))
        ->toBe([
            'course_sections' => [
                'permission_base_name' => 'course_sections',
                'abilities' => ['publish', 'view'],
                'target' => null,
            ],
        ]);
});
