<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warden\Ability;
use Warden\ConditionWithoutTarget;
use Warden\ConditionWithTarget;
use Warden\Facades\Warden;
use Warden\HasWardenPolicy;
use Warden\PermissionResolutionContext;
use Warden\PermissionResolver;
use Warden\WardenManager;
use Warden\WardenPermission;
use Warden\WardenPolicy;

class WardenTestUser implements Authenticatable
{
    public function __construct(public ?string $role_id = null) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->role_id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}

class WardenTestModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';
}

class WardenTestPolicy extends WardenPolicy
{
    public const model = WardenTestModel::class;

    #[Ability]
    public const ABILITY_CREATE = 'create';

    #[Ability]
    public const ABILITY_PUBLISH = 'publish';

    #[Ability]
    public const ABILITY_ARCHIVE = 'archive';

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[Ability]
    public const ABILITY_UPDATE = 'update';

    #[ConditionWithTarget]
    public function conditionIsTeacher(Authenticatable $currentUser, BuilderContract $whereClause, string $entitySqlId): BuilderContract
    {
        return $whereClause->whereRaw("{$entitySqlId} = ?", ["teacher:{$currentUser->role_id}"]);
    }

    #[ConditionWithoutTarget]
    public function conditionIsAdvisor(Authenticatable $currentUser, BuilderContract $whereClause): BuilderContract
    {
        return $whereClause->whereRaw('? = ?', ['advisor', $currentUser->role_id]);
    }
}

class WardenBooleanConditionPolicy extends WardenPolicy
{
    public const model = WardenTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[ConditionWithoutTarget]
    public function conditionIsSuperUser(Authenticatable $currentUser): bool
    {
        return $currentUser->role_id === 'super-role';
    }
}

class WardenScopedModel extends Model
{
    use HasWardenPolicy;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function wardenPolicy(): string
    {
        return WardenScopedModelPolicy::class;
    }
}

class WardenMismatchedScopedModel extends Model
{
    use HasWardenPolicy;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function wardenPolicy(): string
    {
        return WardenTestPolicy::class;
    }
}

class WardenScopedModelPolicy extends WardenTestPolicy
{
    public const model = WardenScopedModel::class;
}

class FakeWardenPermissionResolver implements PermissionResolver
{
    /**
     * @param  array<int, WardenPermission|string>  $permissions
     */
    public function __construct(private array $permissions = []) {}

    public function resolve(PermissionResolutionContext $context): iterable
    {
        /* Returned verbatim: string/object normalization and base-name scoping
           are the policy's responsibility, and tests rely on that. */
        return $this->permissions;
    }
}

/**
 * @param  array<int, class-string<WardenPolicy>>  $policies
 */
function useWardenPolicies(array $policies): void
{
    config()->set('warden.policies', $policies);
    app()->forgetInstance(WardenManager::class);
    Warden::clearResolvedInstances();
}

/**
 * @param  array<int, WardenPermission|string>  $permissions
 */
function bindWardenPermissions(array $permissions): void
{
    app()->instance(PermissionResolver::class, new FakeWardenPermissionResolver($permissions));
}

function makeWardenTestUser(?string $roleId = 'role-1'): Authenticatable
{
    return new WardenTestUser($roleId);
}

function wardenTestQuery(string $table = 'course_sections'): BuilderContract
{
    return DB::connection()->table($table);
}

function normalizeWardenSql(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', trim($sql));
    $sql = preg_replace('/\s*\(\s*/', '(', $sql);
    $sql = preg_replace('/\s*\)\s*/', ')', $sql);

    return preg_replace('/\s*,\s*/', ', ', $sql);
}
