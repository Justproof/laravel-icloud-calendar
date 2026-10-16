<?php

namespace Justproof\IcloudCalendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;

/**
 * Busy time cached per (calendar set, UTC day range) for a few minutes under
 * a generation key, so one bump() forgets every range at once without
 * Cache::tags(). Works on the file store.
 */
class BusyTimeCache
{
    /**
     * @param  array<string, mixed>  $config  the icloud-calendar config array
     */
    public function __construct(private readonly CalDavClient $client, private array $config = []) {}

    /**
     * Cached busy blocks for the range, fetched when nothing is cached.
     *
     * @param  list<string>  $calendarHrefs
     * @return list<BusyBlock>
     *
     * @throws CalendarUnavailable when nothing is cached and iCloud cannot be reached
     */
    public function forRange(array $calendarHrefs, CarbonPeriod $range): array
    {
        return $this->cached($calendarHrefs, $range) ?? $this->fresh($calendarHrefs, $range);
    }

    /**
     * Only what the cache holds for this range, or null.
     *
     * @param  list<string>  $calendarHrefs
     * @return ?list<BusyBlock>
     */
    public function cached(array $calendarHrefs, CarbonPeriod $range): ?array
    {
        $stored = Cache::get($this->key($calendarHrefs, $range));

        return is_array($stored) ? array_map([BusyBlock::class, 'fromArray'], $stored) : null;
    }

    /**
     * Bypass the cache and ask iCloud now, then store the answer. This is the
     * call to make right before you confirm a slot.
     *
     * @param  list<string>  $calendarHrefs
     * @return list<BusyBlock>
     *
     * @throws CalendarUnavailable
     */
    public function fresh(array $calendarHrefs, CarbonPeriod $range): array
    {
        if ($calendarHrefs === [] || ! $this->client->isConfigured()) {
            // Nothing connected: nothing to block, nothing to fail.
            return [];
        }

        [$start, $end] = $this->bounds($range);

        try {
            $blocks = $this->client->busyBlocks($calendarHrefs, CarbonPeriod::create($start, $end));
        } catch (CalendarUnavailable $e) {
            $this->recordFailure($e);

            throw $e;
        }

        Cache::put(
            $this->key($calendarHrefs, $range),
            array_map(fn (BusyBlock $block) => $block->toArray(), $blocks),
            max(1, (int) ($this->config['busy_cache_seconds'] ?? 300)),
        );
        $this->recordSuccess();

        return $blocks;
    }

    /**
     * Forget every cached range. Call it after anything that changes the calendar.
     */
    public function bump(): void
    {
        Cache::forever($this->prefixed('generation'), $this->generation() + 1);
    }

    /**
     * For a connection status line in your admin.
     *
     * @return array{last_ok: ?CarbonImmutable, last_error: ?array{message: string, status: ?int, at: string}}
     */
    public function status(): array
    {
        $lastOk = Cache::get($this->prefixed('last_ok'));
        $error = Cache::get($this->prefixed('last_error'));

        return [
            'last_ok' => is_string($lastOk) ? CarbonImmutable::parse($lastOk) : null,
            'last_error' => is_array($error) ? $error : null,
        ];
    }

    public function recordSuccess(): void
    {
        Cache::forever($this->prefixed('last_ok'), CarbonImmutable::now()->toIso8601String());
        Cache::forget($this->prefixed('last_error'));
    }

    public function recordFailure(CalendarUnavailable $e): void
    {
        Cache::forever($this->prefixed('last_error'), [
            'message' => $e->getMessage(),
            'status' => $e->status,
            'at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    private function generation(): int
    {
        return (int) Cache::get($this->prefixed('generation'), 0);
    }

    /**
     * Ranges are widened to whole UTC days so a picker asking "now to now + 14 days" every second shares one entry.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(CarbonPeriod $range): array
    {
        $start = CarbonImmutable::instance($range->getStartDate())->utc()->startOfDay();
        $end = CarbonImmutable::instance($range->getEndDate() ?? $start->addDays(14))->utc()->endOfDay();

        return [$start, $end];
    }

    /**
     * @param  list<string>  $calendarHrefs
     */
    private function key(array $calendarHrefs, CarbonPeriod $range): string
    {
        [$start, $end] = $this->bounds($range);
        $hrefs = $calendarHrefs;
        sort($hrefs);

        return $this->prefixed(sprintf('busy:%d:%s:%s:%s', $this->generation(), md5(implode('|', $hrefs)), $start->format('Ymd'), $end->format('Ymd')));
    }

    private function prefixed(string $key): string
    {
        return rtrim((string) ($this->config['cache_prefix'] ?? 'icloud-calendar'), ':').':'.$key;
    }
}
