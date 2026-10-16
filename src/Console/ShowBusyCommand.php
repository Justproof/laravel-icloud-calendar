<?php

namespace Justproof\IcloudCalendar\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Justproof\IcloudCalendar\BusyBlock;
use Justproof\IcloudCalendar\CalDavClient;
use Justproof\IcloudCalendar\CalendarInfo;
use Justproof\IcloudCalendar\Exceptions\CalendarUnavailable;

/**
 * Prints the busy blocks the client would hand your slot generator, so you
 * can compare them with what the Calendar app shows before trusting them.
 */
class ShowBusyCommand extends Command
{
    protected $signature = 'icloud-calendar:busy
        {--days=14 : How far ahead to look}
        {--calendar=* : Calendar hrefs to read (default: every calendar in the account)}
        {--tz= : Timezone to print in (default: the configured timezone)}';

    protected $description = 'Show the busy blocks iCloud reports for the next N days';

    public function handle(CalDavClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Set ICLOUD_CALDAV_USERNAME and ICLOUD_CALDAV_APP_PASSWORD first.');

            return self::FAILURE;
        }

        $tz = (string) ($this->option('tz') ?: config('icloud-calendar.timezone', 'UTC'));
        $days = max(1, (int) $this->option('days'));
        $hrefs = array_values(array_filter((array) $this->option('calendar')));

        try {
            if ($hrefs === []) {
                $hrefs = array_map(fn (CalendarInfo $c) => $c->href, $client->discoverCalendars());
            }

            $start = CarbonImmutable::now($tz)->startOfDay();
            $blocks = $client->busyBlocks($hrefs, CarbonPeriod::create($start, $start->addDays($days)));
        } catch (CalendarUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        usort($blocks, fn (BusyBlock $a, BusyBlock $b) => $a->start <=> $b->start);

        if ($blocks === []) {
            $this->info("No busy time in the next {$days} days across ".count($hrefs).' calendar(s).');

            return self::SUCCESS;
        }

        $this->table(
            ['Start ('.$tz.')', 'End', 'Summary', 'UID'],
            array_map(fn (BusyBlock $b) => [
                $b->start->setTimezone($tz)->format('D M j H:i'),
                $b->end->setTimezone($tz)->format('D M j H:i'),
                $b->summary ?? '',
                $b->uid ?? '',
            ], $blocks),
        );

        $this->line(count($blocks).' busy block(s) from '.count($hrefs).' calendar(s).');

        return self::SUCCESS;
    }
}
