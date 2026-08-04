<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warden\Facades\Warden;
use Warden\RuleSyntaxTree\WardenRuleSet;
use Warden\WardenManager;

beforeEach(function () {
    useWardenSchemas([WardenTestSchema::class]);
});

it('resolves the schema for a model class', function () {
    expect(Warden::getSchemaForModelClass(WardenTestModel::class))->toBe(WardenTestSchema::class);
});

it('resolves the schema for a schema key', function () {
    expect(Warden::getSchemaForKey('course_sections'))->toBe(WardenTestSchema::class);
});

it('throws when no schema is registered for a model class', function () {
    expect(fn () => Warden::getSchemaForModelClass('App\\Models\\Nope'))
        ->toThrow(OutOfBoundsException::class, 'No Warden schema registered for model');
});

it('throws when no schema is registered for a schema key', function () {
    expect(fn () => Warden::getSchemaForKey('widgets'))
        ->toThrow(OutOfBoundsException::class, 'No Warden schema registered for schema key');
});

it('lists the registered schemas', function () {
    expect(Warden::registeredSchemas())->toBe([WardenTestSchema::class]);
});

it('throws when two schemas claim the same schema key', function () {
    expect(fn () => new WardenManager([WardenTestSchema::class, WardenScopedModelSchema::class]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate schema for schema key');
});

it('validates a batch of rule sets, each against its own registered schema', function () {
    // Passes silently: every name is declared by the course_sections schema.
    WardenRuleSet::validateAll(
        WardenRuleSet::fromSyntax('course_sections', 'if is_teacher they can view, update'),
        [WardenRuleSet::fromSyntax('course_sections', 'if is_advisor they can publish')],
    );

    expect(true)->toBeTrue();
});

it('throws on the first unknown name across a validateAll batch', function () {
    expect(fn () => WardenRuleSet::validateAll(
        WardenRuleSet::fromSyntax('course_sections', 'if is_teacher they can view'),
        WardenRuleSet::fromSyntax('course_sections', 'they can fly'),
    ))->toThrow(InvalidArgumentException::class, 'Ability [fly]');
});

it('builds a combined no-target abilities bag keyed by schema key', function () {
    bindWardenRules('they can publish, view');

    expect(Warden::getNoTargetAbilitiesBag(makeWardenTestUser('teacher-role'), WardenTestSchema::class))
        ->toBe([
            'course_sections' => [
                'schema_key' => 'course_sections',
                'abilities' => ['publish', 'view'],
                'target' => null,
            ],
        ]);
});
