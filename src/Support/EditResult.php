<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/** The outcome of editing a resource file: the new source, whether it differs, and notices for the developer. */
final class EditResult
{
    /** @param  array<int, string>  $notices */
    public function __construct(
        public readonly string $source,
        public readonly bool $changed,
        public readonly array $notices = [],
    ) {}
}
