<?php

namespace Ernestdefoe\Detour\Rules;

/** A rule that matched, and where it sends the visitor (null for 410). */
final class Hit
{
    /**
     * @param array<string, mixed> $rule
     * @param list<int> $chain ids of the rules followed after the first
     */
    public function __construct(
        public readonly array $rule,
        public readonly ?string $target,
        public readonly int $status,
        public readonly array $chain = [],
    ) {
    }
}
