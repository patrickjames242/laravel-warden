<?php

use Warden\WardenPermission;

it('parses a two-segment permission as an unconditional grant', function () {
    $permission = WardenPermission::fromString('timesheets.update');

    expect($permission->baseName)->toBe('timesheets');
    expect($permission->condition)->toBeNull();
    expect($permission->ability)->toBe('update');
});

it('parses a three-segment permission as a conditional grant', function () {
    $permission = WardenPermission::fromString('timesheets.is_department_manager.update');

    expect($permission->baseName)->toBe('timesheets');
    expect($permission->condition)->toBe('is_department_manager');
    expect($permission->ability)->toBe('update');
});

it('parses a wildcard ability', function () {
    $unconditional = WardenPermission::fromString('timesheets.*');
    expect($unconditional->ability)->toBe('*');
    expect($unconditional->condition)->toBeNull();

    $conditional = WardenPermission::fromString('timesheets.is_department_manager.*');
    expect($conditional->ability)->toBe('*');
    expect($conditional->condition)->toBe('is_department_manager');
});

it('round-trips through toString', function (string $permission) {
    expect(WardenPermission::fromString($permission)->toString())->toBe($permission);
})->with([
    'timesheets.update',
    'timesheets.*',
    'timesheets.is_department_manager.update',
    'timesheets.is_department_manager.*',
]);

it('builds the string form from its parts', function () {
    expect((new WardenPermission('timesheets', null, 'update'))->toString())
        ->toBe('timesheets.update');
    expect((new WardenPermission('timesheets', 'is_department_manager', 'update'))->toString())
        ->toBe('timesheets.is_department_manager.update');
});

it('throws on a permission string with the wrong number of segments', function (string $permission) {
    expect(fn () => WardenPermission::fromString($permission))
        ->toThrow(InvalidArgumentException::class, 'must have 2 or 3 dot-separated segments');
})->with([
    'timesheets',
    'timesheets.is_department_manager.update.extra',
]);

it('rejects a period in any segment', function (string $baseName, ?string $condition, string $ability) {
    expect(fn () => new WardenPermission($baseName, $condition, $ability))
        ->toThrow(InvalidArgumentException::class, 'must not contain a period');
})->with([
    'base name' => ['time.sheets', null, 'update'],
    'condition' => ['timesheets', 'is.manager', 'update'],
    'ability' => ['timesheets', null, 'up.date'],
]);

it('reports whether the ability is a wildcard', function () {
    expect(WardenPermission::fromString('timesheets.*')->isWildcard())->toBeTrue();
    expect(WardenPermission::fromString('timesheets.update')->isWildcard())->toBeFalse();
});

it('reports whether the grant is unconditional', function () {
    expect(WardenPermission::fromString('timesheets.update')->isUnconditional())->toBeTrue();
    expect(WardenPermission::fromString('timesheets.is_department_manager.update')->isUnconditional())->toBeFalse();
});

it('matches an ability directly or through a wildcard', function () {
    $update = WardenPermission::fromString('timesheets.update');
    expect($update->matchesAbility('update'))->toBeTrue();
    expect($update->matchesAbility('view'))->toBeFalse();

    $wildcard = WardenPermission::fromString('timesheets.*');
    expect($wildcard->matchesAbility('update'))->toBeTrue();
    expect($wildcard->matchesAbility('view'))->toBeTrue();
});
