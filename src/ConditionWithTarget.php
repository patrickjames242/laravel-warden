<?php

namespace Warden;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class ConditionWithTarget
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('ConditionWithTarget key cannot be empty.');
        }
    }
}
