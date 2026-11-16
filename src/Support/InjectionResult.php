<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

final class InjectionResult
{
    public const INSERTED = 'inserted';

    public const UNCHANGED = 'unchanged';

    /** The file already names a different policy; that choice is the developer's. */
    public const CONFLICT = 'conflict';

    public const CLASS_NOT_FOUND = 'class_not_found';

    /** @param  array<int, string>  $uses  Class names the new attribute / property refers to. Empty when the result is not ours (a developer's own line). */
    public function __construct(
        public readonly string $source,
        public readonly string $status,
        public readonly array $uses = [],
    ) {}
}
