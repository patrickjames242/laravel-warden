<?php

namespace Warden;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class ConditionWithoutTarget
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('ConditionWithoutTarget key cannot be empty.');
        }
    }
}
