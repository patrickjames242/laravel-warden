<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warden\Facades\Warden;
use Warden\WardenManager;

beforeEach(function () {
    useWardenSchemas([WardenTestSchema::class]);
});

it('resolves the schema for a model class', function () {
    expect(Warden::getSchemaForModelClass(WardenTestModel::class))->toBe(WardenTestSchema::class);
});

it('resolves the schema for a permission base name', function () {
    expect(Warden::getSchemaForPermissionBaseName('course_sections'))->toBe(WardenTestSchema::class);
});

it('throws when no schema is registered for a model class', function () {
    expect(fn () => Warden::getSchemaForModelClass('App\\Models\\Nope'))
        ->toThrow(OutOfBoundsException::class, 'No Warden schema registered for model');
});

it('throws when no schema is registered for a permission base name', function () {
    expect(fn () => Warden::getSchemaForPermissionBaseName('widgets'))
        ->toThrow(OutOfBoundsException::class, 'No Warden schema registered for permission base name');
});

it('lists the registered schemas', function () {
    expect(Warden::registeredSchemas())->toBe([WardenTestSchema::class]);
});

it('throws when two schemas claim the same permission base name', function () {
    expect(fn () => new WardenManager([WardenTestSchema::class, WardenScopedModelSchema::class]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate schema for permission base name');
});

it('builds a combined no-target abilities bag keyed by base name', function () {
    bindWardenRules('they can publish, view');

    expect(Warden::getNoTargetAbilitiesBag(makeWardenTestUser('teacher-role'), WardenTestSchema::class))
        ->toBe([
            'course_sections' => [
                'permission_base_name' => 'course_sections',
                'abilities' => ['publish', 'view'],
                'target' => null,
            ],
        ]);
});
