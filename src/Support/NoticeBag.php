<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/** Collects human-readable notices during a build / erase so the console can print them afterwards. */
final class NoticeBag
{
    /** @var array<int, string> */
    private array $notices = [];

    public function add(string $notice): void
    {
        $this->notices[] = $notice;
    }

    /** @return array<int, string> */
    public function pull(): array
    {
        $notices = $this->notices;
        $this->notices = [];

        return $notices;
    }
}
