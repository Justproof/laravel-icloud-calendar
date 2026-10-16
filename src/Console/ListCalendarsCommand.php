<?php

namespace Justproof\IcloudCalendar\Console;

use Illuminate\Console\Command;
use Justproof\IcloudCalendar\CalDavClient;
use Justproof\IcloudCalendar\CalendarInfo;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;

/**
 * The first thing to run after setting the env vars: proves the password
 * works and prints the hrefs you will store.
 */
class ListCalendarsCommand extends Command
{
    protected $signature = 'icloud-calendar:calendars';

    protected $description = 'List the iCloud calendars the configured account can see';

    public function handle(CalDavClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Set ICLOUD_CALDAV_USERNAME and ICLOUD_CALDAV_APP_PASSWORD first.');

            return self::FAILURE;
        }

        try {
            $calendars = $client->discoverCalendars();
        } catch (CalendarUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($calendars === []) {
            $this->warn('Signed in, but the account has no calendars that hold events.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Color', 'Href'],
            array_map(fn (CalendarInfo $c) => [$c->displayName, $c->color ?? '', $c->href], $calendars),
        );

        return self::SUCCESS;
    }
}
