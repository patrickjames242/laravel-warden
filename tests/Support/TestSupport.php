<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warden\Ability;
use Warden\Facades\Warden;
use Warden\GlobalCondition;
use Warden\HasWardenSchema;
use Warden\RuleResolutionContext;
use Warden\RuleResolver;
use Warden\RuleSyntaxTree\WardenRule;
use Warden\RuleSyntaxTree\WardenRuleSet;
use Warden\Schema\Conditions\GlobalConditionContext;
use Warden\Schema\Conditions\TargetedConditionContext;
use Warden\TargetedCondition;
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

    #[TargetedCondition]
    public function isTeacher(TargetedConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->targetSqlId} = ?", ["teacher:{$c->user->role_id}"]);
    }

    #[GlobalCondition]
    public function isAdvisor(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', ['advisor', $c->user->role_id]);
    }
}

class WardenBooleanConditionSchema extends WardenSchema
{
    public const model = WardenTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[GlobalCondition]
    public function isSuperUser(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'super-role';
    }
}

class MistypedConditionSchema extends WardenSchema
{
    public const model = WardenTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Marked targeted but typed with the global context — a boot-time mistake.
    #[TargetedCondition]
    public function isWrong(GlobalConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class ExtraParamConditionSchema extends WardenSchema
{
    public const model = WardenTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // One context parameter is the whole contract; a second is a mistake.
    #[GlobalCondition]
    public function isWrong(GlobalConditionContext $c, string $extra): bool
    {
        return $extra !== '';
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

class FakeWardenRuleResolver implements RuleResolver
{
    public function __construct(private WardenRuleSet $ruleSet) {}

    public function resolve(RuleResolutionContext $context): WardenRuleSet
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
 * Bind the resolver to a rule set built from Warden syntax. The schema key is
 * irrelevant to the fake (the schema asks for its own rules), so it defaults to
 * the course_sections fixture schema key.
 */
function bindWardenRules(string $syntax, array $bindings = [], string $schemaKey = 'course_sections'): void
{
    app()->instance(
        RuleResolver::class,
        new FakeWardenRuleResolver(WardenRuleSet::fromSyntax($schemaKey, $syntax, $bindings))
    );
}

/**
 * Bind the resolver to an explicit rule set.
 */
function bindWardenRuleSet(WardenRuleSet $ruleSet): void
{
    app()->instance(RuleResolver::class, new FakeWardenRuleResolver($ruleSet));
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
