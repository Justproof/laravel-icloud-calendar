<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Justproof\IcloudCalendar\BusyTimeCache;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;

const WORK = 'https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/';

function busyRange(): CarbonPeriod
{
    return CarbonPeriod::create(CarbonImmutable::parse('2026-11-02 00:00', 'America/Chicago'), CarbonImmutable::parse('2026-11-15 23:59', 'America/Chicago'));
}

it('returns nothing without a network call when no calendar is given', function () {
    Http::fake();

    expect(app(BusyTimeCache::class)->forRange([], busyRange()))->toBe([]);

    Http::assertNothingSent();
});

it('caches a range and refetches on fresh()', function () {
    Http::fake(['*' => Http::response(caldavFixture('events.xml'), 207)]);
    $cache = app(BusyTimeCache::class);

    expect($cache->cached([WORK], busyRange()))->toBeNull();

    $first = $cache->forRange([WORK], busyRange());
    $second = $cache->forRange([WORK], busyRange());

    expect($first)->not->toBeEmpty()
        ->and(count($second))->toBe(count($first))
        ->and($cache->cached([WORK], busyRange()))->toHaveCount(count($first));
    Http::assertSentCount(1);

    $cache->fresh([WORK], busyRange());
    Http::assertSentCount(2);

    expect($cache->status()['last_ok'])->not->toBeNull()
        ->and($cache->status()['last_error'])->toBeNull();
});

it('keys the cache by calendar set regardless of order', function () {
    Http::fake(['*' => Http::response(caldavFixture('events.xml'), 207)]);
    $cache = app(BusyTimeCache::class);
    $home = 'https://p45-caldav.icloud.com/123456/calendars/home-cal-uuid/';

    $cache->forRange([WORK, $home], busyRange());

    expect($cache->cached([$home, WORK], busyRange()))->not->toBeNull();
});

it('forgets every cached range when bumped', function () {
    Http::fake(['*' => Http::response(caldavFixture('events.xml'), 207)]);
    $cache = app(BusyTimeCache::class);

    $cache->forRange([WORK], busyRange());
    $cache->bump();

    expect($cache->cached([WORK], busyRange()))->toBeNull();

    $cache->forRange([WORK], busyRange());
    Http::assertSentCount(2);
});

it('throws and records the error when iCloud fails with nothing cached', function () {
    Http::fake(['*' => Http::response('nope', 503)]);
    $cache = app(BusyTimeCache::class);

    expect(fn () => $cache->forRange([WORK], busyRange()))->toThrow(CalendarUnavailable::class)
        ->and($cache->status()['last_error']['status'])->toBe(503)
        ->and(Cache::get('icloud-calendar:last_error'))->not->toBeNull();
});
