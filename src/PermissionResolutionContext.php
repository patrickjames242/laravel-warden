<?php

declare(strict_types=1);

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warden\Schema\WardenSchema;

final readonly class PermissionResolutionContext
{
    /**
     * @param  class-string<WardenSchema>  $schema
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $permissionBaseName,
        public string $schema,
        public ?Authenticatable $user,
        public ?string $model = null,
    ) {}
}
