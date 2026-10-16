<?php

namespace Justproof\IcloudCalendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Throwable;

/**
 * iCloud CalDAV over plain HTTP: PROPFIND discovery, REPORT for busy time,
 * PUT and DELETE for your own events. Parsing and recurrence expansion are
 * sabre/vobject's job.
 *
 * Discovery against https://caldav.icloud.com with an app-specific password:
 *   1. PROPFIND / (depth 0) for current-user-principal
 *   2. PROPFIND the principal for calendar-home-set (an absolute URL on a pNN-caldav host)
 *   3. PROPFIND the home (depth 1) for every resource whose resourcetype is a calendar
 */
class CalDavClient
{
    private const PROPFIND_PRINCIPAL = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';

    private const PROPFIND_HOME = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>';

    private const PROPFIND_CALENDARS = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/"><d:prop><d:displayname/><d:resourcetype/><ical:calendar-color/><c:supported-calendar-component-set/></d:prop></d:propfind>';

    private const PROPFIND_ETAG = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>';

    /**
     * @param  array<string, mixed>  $config  the icloud-calendar config array
     */
    public function __construct(private array $config = []) {}

    public function isConfigured(): bool
    {
        return $this->username() !== '' && (string) ($this->config['app_password'] ?? '') !== '';
    }

    /**
     * Every calendar in the account's calendar home that can hold events.
     *
     * @return list<CalendarInfo>
     *
     * @throws CalendarUnavailable
     */
    public function discoverCalendars(): array
    {
        $base = $this->baseUrl();

        $principal = $this->firstHref($this->propfind($base.'/', self::PROPFIND_PRINCIPAL, 0), 'current-user-principal');

        if ($principal === null) {
            throw new CalendarUnavailable('iCloud did not return a principal for this account.');
        }

        $home = $this->firstHref($this->propfind($this->resolve($principal, $base), self::PROPFIND_HOME, 0), 'calendar-home-set');

        if ($home === null) {
            throw new CalendarUnavailable('iCloud did not return a calendar home for this account.');
        }

        $homeUrl = $this->resolve($home, $base);
        $calendars = [];

        foreach ($this->propfind($homeUrl, self::PROPFIND_CALENDARS, 1) as $entry) {
            $props = $entry['props'];

            if (! CalDavXml::hasChild($props['resourcetype'] ?? null, CalDavXml::NS_CALDAV, 'calendar')) {
                continue;
            }

            $components = CalDavXml::components($props['supported-calendar-component-set'] ?? null);

            if ($components !== [] && ! in_array('VEVENT', $components, true)) {
                continue;
            }

            $name = trim((string) ($props['displayname']?->textContent ?? ''));
            $color = trim((string) ($props['calendar-color']?->textContent ?? ''));

            $calendars[] = new CalendarInfo(
                $this->resolve($entry['href'], $homeUrl),
                $name !== '' ? $name : 'Untitled calendar',
                $color !== '' ? substr($color, 0, 20) : null,
            );
        }

        return $calendars;
    }

    /**
     * Busy spans from the given calendars within the range, recurring events
     * expanded, transparent, cancelled and owner-declined events dropped.
     *
     * @param  list<string>  $calendarHrefs
     * @return list<BusyBlock>
     *
     * @throws CalendarUnavailable
     */
    public function busyBlocks(array $calendarHrefs, CarbonPeriod $range): array
    {
        $start = CarbonImmutable::instance($range->getStartDate())->utc();
        $end = CarbonImmutable::instance($range->getEndDate() ?? $start->addDays(14))->utc();
        $blocks = [];

        foreach ($calendarHrefs as $href) {
            $response = $this->send('REPORT', $this->resolve($href, $this->baseUrl()), $this->calendarQuery($start, $end), ['Depth' => '1']);

            foreach (CalDavXml::multistatus($response->body()) as $entry) {
                $data = $entry['props']['calendar-data'] ?? null;

                if ($data === null) {
                    continue;
                }

                foreach ($this->blocksFromIcs($data->textContent, $start, $end) as $block) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * Write (or rewrite) an event. The first write sends If-None-Match: * so a
     * retry after a lost response never clobbers a successful one. Pass the
     * ETag from the previous write to update in place.
     *
     * @return array{href: string, etag: ?string}
     *
     * @throws CalendarUnavailable
     */
    public function putEvent(string $calendarHref, string $uid, string $ics, ?string $etag = null): array
    {
        $url = rtrim($this->resolve($calendarHref, $this->baseUrl()), '/').'/'.rawurlencode($uid).'.ics';
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        $headers[$etag === null ? 'If-None-Match' : 'If-Match'] = $etag ?? '*';

        $response = $this->send('PUT', $url, $ics, $headers, allow: [412]);

        if ($response->status() === 412 && $etag === null) {
            // Already there from an earlier attempt whose response we lost.
            return ['href' => $url, 'etag' => $this->etagOf($url)];
        }

        if ($response->status() === 412) {
            // The event changed on the server (the owner edited it on a device). The caller's
            // record is the source of truth, so take the current ETag and write once more.
            $current = $this->etagOf($url);

            if ($current === null || $current === $etag) {
                throw new CalendarUnavailable('iCloud rejected the update: the event changed on the server.', 412);
            }

            $headers['If-Match'] = $current;
            $response = $this->send('PUT', $url, $ics, $headers);
        }

        $newEtag = $response->header('ETag') ?: $this->etagOf($url);

        return ['href' => $url, 'etag' => $newEtag !== '' ? $newEtag : null];
    }

    /**
     * Delete an event by its href. A 404 is fine: it is already gone.
     *
     * @throws CalendarUnavailable
     */
    public function deleteEvent(string $href, ?string $etag = null): void
    {
        $headers = $etag !== null ? ['If-Match' => $etag] : [];

        $this->send('DELETE', $this->resolve($href, $this->baseUrl()), null, $headers, allow: [404, 412]);
    }

    /**
     * @return list<array{href: string, props: array<string, \DOMElement>}>
     */
    private function propfind(string $url, string $body, int $depth): array
    {
        $response = $this->send('PROPFIND', $url, $body, ['Depth' => (string) $depth]);

        return CalDavXml::multistatus($response->body());
    }

    /**
     * @param  list<array{href: string, props: array<string, \DOMElement>}>  $entries
     */
    private function firstHref(array $entries, string $prop): ?string
    {
        foreach ($entries as $entry) {
            $href = CalDavXml::hrefIn($entry['props'][$prop] ?? null);

            if ($href !== null) {
                return $href;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<int>  $allow  non-2xx statuses the caller handles itself
     */
    private function send(string $method, string $url, ?string $body, array $headers = [], array $allow = []): Response
    {
        try {
            $request = $this->http()->withHeaders($headers);

            if ($body !== null) {
                $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/xml; charset=utf-8');
            }

            $response = $request->send($method, $url);
        } catch (ConnectionException $e) {
            throw new CalendarUnavailable('iCloud did not answer in time: '.$e->getMessage(), null, $e);
        } catch (Throwable $e) {
            throw new CalendarUnavailable('iCloud request failed: '.$e->getMessage(), null, $e);
        }

        if ($response->successful() || in_array($response->status(), $allow, true)) {
            return $response;
        }

        $reason = match ($response->status()) {
            401 => 'iCloud refused the app-specific password (401). Generate a new one at account.apple.com.',
            403 => 'iCloud refused access to that calendar (403).',
            default => "iCloud answered {$response->status()} for {$method}.",
        };

        throw new CalendarUnavailable($reason, $response->status());
    }

    private function http(): PendingRequest
    {
        $timeout = max(1, (int) ($this->config['timeout'] ?? 5));

        return Http::withBasicAuth($this->username(), (string) ($this->config['app_password'] ?? ''))
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->accept('application/xml')
            ->withUserAgent((string) ($this->config['user_agent'] ?? 'Laravel iCloud Calendar/1.0'));
    }

    private function etagOf(string $url): ?string
    {
        foreach ($this->propfind($url, self::PROPFIND_ETAG, 0) as $entry) {
            $etag = trim((string) ($entry['props']['getetag']?->textContent ?? ''));

            if ($etag !== '') {
                return $etag;
            }
        }

        return null;
    }

    private function username(): string
    {
        return (string) ($this->config['username'] ?? '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://caldav.icloud.com'), '/');
    }

    /**
     * iCloud mixes absolute URLs (the calendar home on a pNN host) with paths.
     */
    private function resolve(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin.'/'.ltrim($href, '/');
    }

    private function calendarQuery(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $format = fn (CarbonImmutable $time): string => $time->utc()->format('Ymd\THis\Z');

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            .'<c:time-range start="'.$format($start).'" end="'.$format($end).'"/>'
            .'</c:comp-filter></c:comp-filter></c:filter>'
            .'</c:calendar-query>';
    }

    /**
     * Expand one calendar object into busy blocks inside the window.
     *
     * @return list<BusyBlock>
     */
    private function blocksFromIcs(string $ics, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);

            if (! $calendar instanceof VCalendar) {
                return [];
            }

            $expanded = $calendar->expand(new DateTimeImmutable($start->toIso8601String()), new DateTimeImmutable($end->toIso8601String()));
        } catch (Throwable $e) {
            Log::warning('CalDAV event could not be parsed', ['message' => $e->getMessage()]);

            return [];
        }

        $blocks = [];

        foreach ($expanded->select('VEVENT') as $event) {
            if (! $event instanceof VEvent || $this->isIgnorable($event)) {
                continue;
            }

            [$eventStart, $eventEnd] = $this->span($event);

            if ($eventStart === null || $eventEnd === null || $eventEnd->lessThanOrEqualTo($eventStart)) {
                continue;
            }

            $blocks[] = new BusyBlock($eventStart, $eventEnd, isset($event->UID) ? (string) $event->UID : null, isset($event->SUMMARY) ? (string) $event->SUMMARY : null);
        }

        return $blocks;
    }

    /**
     * Transparent events, cancelled events and invitations the owner declined do not block.
     */
    private function isIgnorable(VEvent $event): bool
    {
        if (isset($event->TRANSP) && strtoupper((string) $event->TRANSP) === 'TRANSPARENT') {
            return true;
        }

        if (isset($event->STATUS) && strtoupper((string) $event->STATUS) === 'CANCELLED') {
            return true;
        }

        $owner = array_values(array_filter(array_map('strtolower', array_map('strval', [
            $this->username(),
            ...(array) ($this->config['owner_emails'] ?? []),
        ]))));

        foreach ($event->select('ATTENDEE') as $attendee) {
            if (! $attendee instanceof Property) {
                continue;
            }

            $email = strtolower(preg_replace('/^mailto:/i', '', (string) $attendee->getValue()));
            $status = strtoupper((string) ($attendee['PARTSTAT'] ?? ''));

            if ($status === 'DECLINED' && in_array($email, $owner, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function span(VEvent $event): array
    {
        if (! isset($event->DTSTART)) {
            return [null, null];
        }

        $tz = new DateTimeZone((string) ($this->config['timezone'] ?? 'UTC'));
        $allDay = ! $event->DTSTART->hasTime();

        if ($allDay) {
            $start = CarbonImmutable::parse($event->DTSTART->getValue(), $tz)->startOfDay();
            $end = isset($event->DTEND)
                ? CarbonImmutable::parse($event->DTEND->getValue(), $tz)->startOfDay()
                : $start->addDay();

            return [$start, $end];
        }

        $start = CarbonImmutable::instance($event->DTSTART->getDateTime());

        if (isset($event->DTEND)) {
            $end = CarbonImmutable::instance($event->DTEND->getDateTime());
        } elseif (isset($event->DURATION)) {
            $end = $start->add(DateTimeParser::parseDuration((string) $event->DURATION));
        } else {
            $end = $start;
        }

        return [$start, $end];
    }
}
