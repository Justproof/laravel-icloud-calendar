<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Justproof\IcloudCalendar\BusyBlock;
use Justproof\IcloudCalendar\CalDavClient;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;

it('discovers the account calendars that can hold events', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            $request->url() === 'https://caldav.icloud.com/' => Http::response(caldavFixture('principal.xml'), 207),
            $request->url() === 'https://caldav.icloud.com/123456/principal/' => Http::response(caldavFixture('home.xml'), 207),
            $request->url() === 'https://p45-caldav.icloud.com/123456/calendars/' => Http::response(caldavFixture('calendars.xml'), 207),
            default => Http::response('unexpected '.$request->url(), 500),
        };
    });

    $calendars = app(CalDavClient::class)->discoverCalendars();

    expect($calendars)->toHaveCount(2)
        ->and($calendars[0]->toArray())->toBe(['href' => 'https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/', 'display_name' => 'Work', 'color' => '#1BADF8FF'])
        ->and($calendars[1]->displayName)->toBe('Home');

    Http::assertSent(fn (Request $request) => $request->method() === 'PROPFIND' && $request->hasHeader('Depth', '1') && $request->hasHeader('Authorization'));
});

it('reads busy blocks with recurrences expanded and noise dropped', function () {
    Http::fake(['p45-caldav.icloud.com/*' => Http::response(caldavFixture('events.xml'), 207)]);

    $range = CarbonPeriod::create(CarbonImmutable::parse('2026-11-02 00:00', 'America/Chicago'), CarbonImmutable::parse('2026-11-15 23:59', 'America/Chicago'));
    $blocks = app(CalDavClient::class)->busyBlocks(['https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/'], $range);

    $byUid = collect($blocks)->groupBy(fn (BusyBlock $block) => $block->uid);

    expect($byUid->keys()->all())->toContain('timed-event@icloud.com', 'weekly-standup@icloud.com', 'allday-offsite@icloud.com')
        ->not->toContain('transparent-note@icloud.com', 'cancelled-lunch@icloud.com', 'declined-invite@example.com');

    $timed = $byUid['timed-event@icloud.com']->first();
    expect($timed->start->toIso8601ZuluString())->toBe('2026-11-03T16:00:00Z') // 10 AM CST
        ->and($timed->end->toIso8601ZuluString())->toBe('2026-11-03T17:00:00Z');

    $standups = $byUid['weekly-standup@icloud.com']->map(fn (BusyBlock $b) => $b->start->setTimezone('America/Chicago')->format('Y-m-d H:i'))->all();
    expect($standups)->toContain('2026-11-02 09:00')->not->toContain('2026-11-09 09:00');

    $allDay = $byUid['allday-offsite@icloud.com']->first();
    expect($allDay->start->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-11-05 00:00')
        ->and($allDay->end->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-11-06 00:00');

    Http::assertSent(fn (Request $request) => $request->method() === 'REPORT' && str_contains($request->body(), 'time-range start="20261102T'));
});

it('treats a declined invitation as free only for the owner addresses', function () {
    Http::fake(['p45-caldav.icloud.com/*' => Http::response(caldavFixture('events.xml'), 207)]);
    $range = CarbonPeriod::create(CarbonImmutable::parse('2026-11-02', 'UTC'), CarbonImmutable::parse('2026-11-15', 'UTC'));

    $stranger = new CalDavClient(['username' => 'someone-else@icloud.com', 'app_password' => 'x', 'timezone' => 'UTC']);
    $uids = collect($stranger->busyBlocks(['https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/'], $range))->pluck('uid');

    expect($uids)->toContain('declined-invite@example.com');

    $alias = new CalDavClient(['username' => 'someone-else@icloud.com', 'app_password' => 'x', 'timezone' => 'UTC', 'owner_emails' => ['owner@icloud.com']]);
    $uids = collect($alias->busyBlocks(['https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/'], $range))->pluck('uid');

    expect($uids)->not->toContain('declined-invite@example.com');
});

it('turns a 401 into CalendarUnavailable rather than an empty list', function () {
    Http::fake(['*' => Http::response(caldavFixture('unauthorized.html'), 401)]);

    expect(fn () => app(CalDavClient::class)->busyBlocks(['https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/'], CarbonPeriod::create(now(), now()->addDays(7))))
        ->toThrow(fn (CalendarUnavailable $e) => expect($e->isUnauthorized())->toBeTrue()->and($e->getMessage())->toContain('app-specific password'));
});

it('turns a timeout into CalendarUnavailable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => app(CalDavClient::class)->discoverCalendars())
        ->toThrow(CalendarUnavailable::class, 'did not answer in time');
});

it('writes an event with If-None-Match on the first PUT and If-Match afterwards', function () {
    Http::fake(['p45-caldav.icloud.com/*' => Http::response('', 201, ['ETag' => '"v1"'])]);
    $client = app(CalDavClient::class);

    $first = $client->putEvent('https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/', 'abc@example.com', 'BEGIN:VCALENDAR', null);
    $second = $client->putEvent('https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/', 'abc@example.com', 'BEGIN:VCALENDAR', '"v1"');

    expect($first)->toBe(['href' => 'https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/abc%40example.com.ics', 'etag' => '"v1"'])
        ->and($second['etag'])->toBe('"v1"');

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('If-None-Match', '*') && $r->hasHeader('Content-Type', 'text/calendar; charset=utf-8'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('If-Match', '"v1"'));
});

it('treats a 412 on the first PUT as an earlier write that succeeded', function () {
    Http::fake(function (Request $request) {
        return match ($request->method()) {
            'PUT' => Http::response('', 412),
            'PROPFIND' => Http::response('<?xml version="1.0"?><multistatus xmlns="DAV:"><response><href>/x.ics</href><propstat><prop><getetag>"already"</getetag></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>', 207),
            default => Http::response('', 500),
        };
    });

    $result = app(CalDavClient::class)->putEvent('https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/', 'abc@example.com', 'BEGIN:VCALENDAR', null);

    expect($result['etag'])->toBe('"already"');
});

it('accepts a 404 when deleting an event that is already gone', function () {
    Http::fake(['*' => Http::response('', 404)]);

    app(CalDavClient::class)->deleteEvent('https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/abc.ics', '"v1"');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->hasHeader('If-Match', '"v1"'));
});

it('knows when it is not configured', function () {
    expect((new CalDavClient(['username' => 'owner@icloud.com']))->isConfigured())->toBeFalse()
        ->and((new CalDavClient(['username' => 'owner@icloud.com', 'app_password' => 'x']))->isConfigured())->toBeTrue();
});
