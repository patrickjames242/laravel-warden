<?php

namespace Warden;

use Attribute;

/**
 * Declares a check-time context key on a schema. The constant's *value* is the
 * key string a rule references with `@context <key>` and that callers supply in
 * the `context:` bag; the constant's name is irrelevant to Warden (mirroring
 * `#[Ability]`).
 *
 * `required: true` makes the key mandatory: any check on the schema throws
 * unless the key is present in the effective context (explicitly passed or
 * supplied by `defaultContext()`). Leave it false for an optional frame.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class ContextKey
{
    public function __construct(public bool $required = false) {}
}
