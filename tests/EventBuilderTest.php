<?php

use Carbon\CarbonImmutable;
use Justproof\IcloudCalendar\EventBuilder;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

function builder(): EventBuilder
{
    return EventBuilder::make()
        ->uid('booking-1@example.com')
        ->summary('30-minute call: Ada and Example')
        ->between(CarbonImmutable::parse('2026-11-03 10:00', 'America/Chicago'), CarbonImmutable::parse('2026-11-03 10:30', 'America/Chicago'))
        ->organizer('hello@example.com', 'Example')
        ->attendee('ada@example.com', 'Ada Lovelace')
        ->description("Phone call\nReschedule: https://example.com/b/1")
        ->location('Phone call: +1 555 0100')
        ->sequence(2);
}

function parse(string $ics): VCalendar
{
    $calendar = Reader::read($ics);
    expect($calendar)->toBeInstanceOf(VCalendar::class);

    return $calendar;
}

it('builds a stored object with no METHOD and SCHEDULE-AGENT=CLIENT', function () {
    $event = parse(builder()->forCalendar())->VEVENT;

    expect(isset(parse(builder()->forCalendar())->METHOD))->toBeFalse()
        ->and((string) $event->UID)->toBe('booking-1@example.com')
        ->and((string) $event->ATTENDEE['SCHEDULE-AGENT'])->toBe('CLIENT')
        ->and((string) $event->ORGANIZER)->toBe('mailto:hello@example.com')
        ->and((string) $event->ORGANIZER['CN'])->toBe('Example')
        ->and((int) (string) $event->SEQUENCE)->toBe(2)
        ->and((string) $event->STATUS)->toBe('CONFIRMED')
        ->and($event->DTSTART->getDateTime()->format('c'))->toBe('2026-11-03T16:00:00+00:00');
});

it('builds REQUEST, CANCEL and PUBLISH without SCHEDULE-AGENT', function () {
    $request = parse(builder()->request());
    $cancel = parse(builder()->cancel());
    $publish = parse(builder()->publish());

    expect((string) $request->METHOD)->toBe('REQUEST')
        ->and((string) $request->VEVENT->STATUS)->toBe('CONFIRMED')
        ->and($request->VEVENT->ATTENDEE['SCHEDULE-AGENT'])->toBeNull()
        ->and((string) $request->VEVENT->ATTENDEE['RSVP'])->toBe('TRUE')
        ->and((string) $cancel->METHOD)->toBe('CANCEL')
        ->and((string) $cancel->VEVENT->STATUS)->toBe('CANCELLED')
        ->and((string) $cancel->VEVENT->UID)->toBe('booking-1@example.com')
        ->and((string) $publish->METHOD)->toBe('PUBLISH');
});

it('marks a cancelled stored object', function () {
    expect((string) parse(builder()->forCalendar(cancelled: true))->VEVENT->STATUS)->toBe('CANCELLED');
});

it('refuses to build without a uid and times', function () {
    expect(fn () => EventBuilder::make()->summary('x')->request())->toThrow(LogicException::class);
});
