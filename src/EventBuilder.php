<?php

namespace Justproof\IcloudCalendar;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * One event, four outputs: the object you PUT to iCloud, and the REQUEST,
 * CANCEL and PUBLISH files you email or offer for download.
 *
 * Two rules keep mail clients calm. ORGANIZER is the address the email comes
 * from, on every message, so Gmail and Apple Mail see one organiser across
 * invite, update and cancel. And the stored iCloud object carries
 * SCHEDULE-AGENT=CLIENT on the attendee, so iCloud does not send its own
 * invite from the Apple ID on top of yours.
 */
class EventBuilder
{
    private string $prodId = '-//Justproof//Laravel iCloud Calendar//EN';

    private ?string $uid = null;

    private string $summary = '';

    private ?CarbonImmutable $start = null;

    private ?CarbonImmutable $end = null;

    private ?string $description = null;

    private ?string $location = null;

    private ?string $url = null;

    private int $sequence = 0;

    /** @var array{email: string, name: ?string}|null */
    private ?array $organizer = null;

    /** @var list<array{email: string, name: ?string}> */
    private array $attendees = [];

    public static function make(): self
    {
        return new self;
    }

    public function prodId(string $prodId): self
    {
        $this->prodId = $prodId;

        return $this;
    }

    public function uid(string $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $this->start = CarbonImmutable::instance($start)->utc();
        $this->end = CarbonImmutable::instance($end)->utc();

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function location(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Bump this on every change you send out; calendars ignore an update with a stale SEQUENCE.
     */
    public function sequence(int $sequence): self
    {
        $this->sequence = max(0, $sequence);

        return $this;
    }

    public function organizer(string $email, ?string $name = null): self
    {
        $this->organizer = ['email' => $email, 'name' => $name];

        return $this;
    }

    public function attendee(string $email, ?string $name = null): self
    {
        $this->attendees[] = ['email' => $email, 'name' => $name];

        return $this;
    }

    /**
     * The stored object for CalDAV: no METHOD (servers reject it in stored
     * objects) and SCHEDULE-AGENT=CLIENT so iCloud sends no invite of its own.
     */
    public function forCalendar(bool $cancelled = false): string
    {
        return $this->build(null, $cancelled ? 'CANCELLED' : 'CONFIRMED', scheduleAgentClient: true)->serialize();
    }

    /**
     * METHOD:REQUEST for a new or rescheduled event (SEQUENCE carries the version).
     */
    public function request(): string
    {
        return $this->build('REQUEST', 'CONFIRMED', scheduleAgentClient: false)->serialize();
    }

    /**
     * METHOD:CANCEL so the attendee's calendar removes the event.
     */
    public function cancel(): string
    {
        return $this->build('CANCEL', 'CANCELLED', scheduleAgentClient: false)->serialize();
    }

    /**
     * METHOD:PUBLISH for an "Add to calendar" download: imports as a plain event, no reply asked.
     */
    public function publish(): string
    {
        return $this->build('PUBLISH', 'CONFIRMED', scheduleAgentClient: false)->serialize();
    }

    private function build(?string $method, string $status, bool $scheduleAgentClient): VCalendar
    {
        if ($this->uid === null || $this->start === null || $this->end === null) {
            throw new \LogicException('EventBuilder needs uid() and between() before it can build.');
        }

        $calendar = new VCalendar;
        $calendar->PRODID = $this->prodId;
        $calendar->VERSION = '2.0';

        if ($method !== null) {
            $calendar->METHOD = $method;
        }

        $utc = new DateTimeZone('UTC');

        /** @var VEvent $event */
        $event = $calendar->add('VEVENT', [
            'UID' => $this->uid,
            'SUMMARY' => $this->summary,
            'DTSTART' => new DateTimeImmutable($this->start->toIso8601String(), $utc),
            'DTEND' => new DateTimeImmutable($this->end->toIso8601String(), $utc),
            'DTSTAMP' => CarbonImmutable::now('UTC')->toDateTimeImmutable(),
            'SEQUENCE' => $this->sequence,
            'STATUS' => $status,
        ]);

        if ($this->description !== null && $this->description !== '') {
            $event->add('DESCRIPTION', $this->description);
        }

        if ($this->location !== null && $this->location !== '') {
            $event->add('LOCATION', $this->location);
        }

        if ($this->url !== null && $this->url !== '') {
            $event->add('URL', $this->url);
        }

        if ($this->organizer !== null) {
            $event->add('ORGANIZER', 'mailto:'.$this->organizer['email'], array_filter(['CN' => $this->organizer['name']]));
        }

        foreach ($this->attendees as $attendee) {
            $params = array_filter([
                'CN' => $attendee['name'],
                'ROLE' => 'REQ-PARTICIPANT',
                'PARTSTAT' => 'NEEDS-ACTION',
                'RSVP' => 'TRUE',
            ]);

            if ($scheduleAgentClient) {
                $params['SCHEDULE-AGENT'] = 'CLIENT';
            }

            $event->add('ATTENDEE', 'mailto:'.$attendee['email'], $params);
        }

        return $calendar;
    }
}
