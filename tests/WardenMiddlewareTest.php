<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Warden\AbilityMatchMode;
use Warden\WardenMiddleware;

beforeEach(function () {
    useWardenPolicies([WardenScopedModelPolicy::class]);
});

function registerWardenTestRoute(string $uri, string $middleware): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->get($uri, fn () => response('ok'));
}

it('allows non-target checks by permission base name', function () {
    bindWardenPermissions(['course_sections.publish']);

    registerWardenTestRoute('/__warden/non-target', 'warden:course_sections,all,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/non-target')
        ->assertOk()
        ->assertSee('ok');
});

it('defaults the match mode to all when omitted', function () {
    bindWardenPermissions(['course_sections.publish']);

    registerWardenTestRoute('/__warden/default-all', 'warden:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/default-all')
        ->assertOk();
});

it('allows targeted checks by route parameter name', function () {
    bindWardenPermissions(['course_sections.is_teacher.view']);

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WardenScopedModel::query()->find($value));
    registerWardenTestRoute('/__warden/target/{course_section}', 'warden:course_section,all,view');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/target/teacher:teacher-role')
        ->assertOk();
});

it('denies requests when the user lacks the abilities', function () {
    bindWardenPermissions([]);

    registerWardenTestRoute('/__warden/forbidden', 'warden:course_sections,all,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/forbidden')
        ->assertForbidden();
});

it('rejects targeted checks when the route parameter is not a model instance', function () {
    registerWardenTestRoute('/__warden/invalid/{course_section}', 'warden:course_section,all,view');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/invalid/teacher:teacher-role'))
        ->toThrow(InvalidArgumentException::class, 'must resolve to a model instance');
});

it('builds middleware strings with an implicit all match mode', function () {
    expect(WardenMiddleware::string('course_sections', 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string(WardenScopedModelPolicy::class, 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string(WardenScopedModel::class, 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string('course_sections', ['view', 'update'], AbilityMatchMode::ANY))
        ->toBe('warden:course_sections,any,view,update');
});

it('rejects unmapped model classes when building middleware strings', function () {
    expect(fn () => WardenMiddleware::string(WardenTestModel::class, 'publish'))
        ->toThrow(InvalidArgumentException::class, 'Unable to resolve');
});

it('builds middleware strings for standard ability helpers', function () {
    expect(WardenMiddleware::canView('course_sections'))->toBe('warden:course_sections,view');
    expect(WardenMiddleware::canCreate('course_sections'))->toBe('warden:course_sections,create');
    expect(WardenMiddleware::canUpdate('course_sections'))->toBe('warden:course_sections,update');
    expect(WardenMiddleware::canDelete('course_sections'))->toBe('warden:course_sections,delete');
    expect(WardenMiddleware::canArchive('course_sections'))->toBe('warden:course_sections,archive');
});

it('guards a route group with the generated middleware string', function () {
    bindWardenPermissions(['course_sections.publish']);

    Route::middleware(SubstituteBindings::class)->group(function () {
        WardenMiddleware::guard('course_sections', 'publish', function () {
            Route::get('/__warden/guard', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/guard')
        ->assertOk();
});

it('guards a route group with a standard ability helper', function () {
    bindWardenPermissions(['course_sections.is_teacher.view']);

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WardenScopedModel::query()->find($value));

    Route::middleware(SubstituteBindings::class)->group(function () {
        WardenMiddleware::canView('course_section', function () {
            Route::get('/__warden/can-view/{course_section}', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/can-view/teacher:teacher-role')
        ->assertOk();
});
