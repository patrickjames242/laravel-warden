<?php

declare(strict_types=1);

namespace Warden;

use InvalidArgumentException;

final readonly class WardenPermission
{
    public function __construct(
        public string $baseName,
        public ?string $condition,
        public string $ability,
    ) {
        /* The period is reserved as the segment separator, so no segment may
           contain one — otherwise toString would not round-trip. */
        foreach (['base name' => $baseName, 'condition' => $condition, 'ability' => $ability] as $label => $segment) {
            if ($segment !== null && str_contains($segment, '.')) {
                throw new InvalidArgumentException(
                    sprintf('Warden permission %s [%s] must not contain a period.', $label, $segment)
                );
            }
        }
    }

    public function toString(): string
    {
        if ($this->condition === null) {
            return "{$this->baseName}.{$this->ability}";
        }

        return "{$this->baseName}.{$this->condition}.{$this->ability}";
    }

    public static function fromString(string $permission): self
    {
        $segments = explode('.', $permission);

        if (count($segments) === 2) {
            return new self(
                baseName: $segments[0],
                condition: null,
                ability: $segments[1],
            );
        }

        if (count($segments) === 3) {
            return new self(
                baseName: $segments[0],
                condition: $segments[1],
                ability: $segments[2],
            );
        }

        throw new InvalidArgumentException(
            sprintf('Permission string [%s] must have 2 or 3 dot-separated segments.', $permission)
        );
    }

    public function isWildcard(): bool
    {
        return $this->ability === '*';
    }

    public function isUnconditional(): bool
    {
        return $this->condition === null;
    }

    public function matchesAbility(string $ability): bool
    {
        return $this->ability === '*' || $this->ability === $ability;
    }
}
