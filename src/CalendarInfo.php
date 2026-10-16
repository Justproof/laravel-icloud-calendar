<?php

namespace Justproof\IcloudCalendar;

/**
 * One calendar collection from the account's calendar home.
 */
final class CalendarInfo
{
    public function __construct(
        public readonly string $href,
        public readonly string $displayName,
        public readonly ?string $color = null,
    ) {}

    /**
     * @return array{href: string, display_name: string, color: ?string}
     */
    public function toArray(): array
    {
        return ['href' => $this->href, 'display_name' => $this->displayName, 'color' => $this->color];
    }
}
