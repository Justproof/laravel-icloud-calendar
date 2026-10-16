<?php

namespace Justproof\IcloudCalendar;

use Carbon\CarbonImmutable;

/**
 * A span of time that is taken. Always UTC, whatever the calendar said.
 */
final class BusyBlock
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(CarbonImmutable $start, CarbonImmutable $end, public readonly ?string $uid = null, public readonly ?string $summary = null)
    {
        $this->start = $start->utc();
        $this->end = $end->utc();
    }

    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->start->lessThan($end) && $this->end->greaterThan($start);
    }

    /**
     * @return array{start: string, end: string, uid: ?string, summary: ?string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601ZuluString(),
            'end' => $this->end->toIso8601ZuluString(),
            'uid' => $this->uid,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param  array{start: string, end: string, uid?: ?string, summary?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            CarbonImmutable::parse($data['start'], 'UTC'),
            CarbonImmutable::parse($data['end'], 'UTC'),
            $data['uid'] ?? null,
            $data['summary'] ?? null,
        );
    }
}
