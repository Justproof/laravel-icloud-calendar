<?php

namespace Justproof\IcloudCalendar\Facades;

use Illuminate\Support\Facades\Facade;
use Justproof\IcloudCalendar\CalDavClient;

/**
 * @method static bool isConfigured()
 * @method static list<\Justproof\IcloudCalendar\CalendarInfo> discoverCalendars()
 * @method static list<\Justproof\IcloudCalendar\BusyBlock> busyBlocks(array $calendarHrefs, \Carbon\CarbonPeriod $range)
 * @method static array{href: string, etag: ?string} putEvent(string $calendarHref, string $uid, string $ics, ?string $etag = null)
 * @method static void deleteEvent(string $href, ?string $etag = null)
 *
 * @see CalDavClient
 */
class IcloudCalendar extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CalDavClient::class;
    }
}
