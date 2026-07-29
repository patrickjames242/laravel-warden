<?php

declare(strict_types=1);

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class PermissionResolutionContext
{
    /**
     * @param  class-string<WardenPolicy>  $policy
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $permissionBaseName,
        public string $policy,
        public ?Authenticatable $user,
        public ?string $model = null,
    ) {}
}
