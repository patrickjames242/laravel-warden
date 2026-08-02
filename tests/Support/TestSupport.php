<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warden\Ability;
use Warden\ConditionWithoutTarget;
use Warden\ConditionWithTarget;
use Warden\Facades\Warden;
use Warden\HasWardenSchema;
use Warden\PermissionResolutionContext;
use Warden\PermissionResolver;
use Warden\RuleSyntaxTree\WardenRule;
use Warden\RuleSyntaxTree\WardenRuleSet;
use Warden\Schema\WardenSchema;
use Warden\WardenManager;

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

class WardenTestSchema extends WardenSchema
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

class WardenBooleanConditionSchema extends WardenSchema
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
    use HasWardenSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function wardenSchema(): string
    {
        return WardenScopedModelSchema::class;
    }
}

class WardenMismatchedScopedModel extends Model
{
    use HasWardenSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function wardenSchema(): string
    {
        return WardenTestSchema::class;
    }
}

class WardenScopedModelSchema extends WardenTestSchema
{
    public const model = WardenScopedModel::class;
}

class WardenImplicitRulesSchema extends WardenTestSchema
{
    protected function implicitRules(): array
    {
        return [
            // Always grant publish, and never allow archive, regardless of the resolver.
            WardenRule::fromSyntax('they can publish'),
            WardenRule::fromSyntax('they cannot archive'),
        ];
    }
}

class FakeWardenPermissionResolver implements PermissionResolver
{
    public function __construct(private WardenRuleSet $ruleSet) {}

    public function resolve(PermissionResolutionContext $context): WardenRuleSet
    {
        return $this->ruleSet;
    }
}

/**
 * @param  array<int, class-string<WardenSchema>>  $schemas
 */
function useWardenSchemas(array $schemas): void
{
    config()->set('warden.schemas', $schemas);
    app()->forgetInstance(WardenManager::class);
    Warden::clearResolvedInstances();
}

/**
 * Bind the resolver to a rule set built from Warden syntax. The entity name is
 * irrelevant to the fake (the schema asks for its own rules), so it defaults to
 * the course_sections fixture base name.
 */
function bindWardenRules(string $syntax, array $bindings = [], string $entityName = 'course_sections'): void
{
    app()->instance(
        PermissionResolver::class,
        new FakeWardenPermissionResolver(WardenRuleSet::fromSyntax($entityName, $syntax, $bindings))
    );
}

/**
 * Bind the resolver to an explicit rule set.
 */
function bindWardenRuleSet(WardenRuleSet $ruleSet): void
{
    app()->instance(PermissionResolver::class, new FakeWardenPermissionResolver($ruleSet));
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
