# Laravel iCloud Calendar

Read busy time from, and write events to, iCloud calendars over CalDAV from a Laravel app. An app-specific password goes in; free slots and calendar invites come out. No OAuth dance, no Apple developer account, no SDK: iCloud speaks plain HTTP and this package speaks it back.

Pulled out of the booking flow on [pcdrama.com](https://pcdrama.com), where it replaced a Calendly embed. Everything in here was learned by making a real booking, watching two invites arrive, and working out why.

## Why it exists

A scheduler is only as good as its view of your week. Calendly reads your calendar for you, charges per seat, and puts its own logo between you and the person booking. We wanted the booking page on our own domain, the leads in our own database, and the calendar we already live in (iCloud) as the single source of truth for when we are free.

Apple publishes no calendar API for that, but every iCloud calendar is a CalDAV collection. This package is the part of our scheduler that talks to it: what is busy, put this booking on the calendar, take it off again. Everything above that line (meeting types, hours, limits, the picker, the emails) stays in your app, because that is where your rules live.

The owner line: your booking page checks the same calendar your phone does, so a dentist appointment added on the train closes that slot within five minutes.

## The scheduler it powers

For a sense of what you can build on top, this is the in-house scheduler running on pcdrama.com. None of it ships in this package; the package supplies the calendar half of each item.

- **Meeting types** with their own duration, buffers, minimum notice, booking window, slot interval, and how to meet (phone, video link, or the guest's choice of either).
- **Weekly hours plus dated overrides** for the owner, and per-type hours that can only narrow them (sales calls mornings only, support any open hour).
- **Daily and weekly limits**, globally and per type, counted in the owner's timezone with Monday weeks.
- **A slot picker** that removes anything iCloud marks busy, then rechecks a fresh busy list under a lock at confirm time so two people tapping 2:30 cannot both get it.
- **A routing question** on `/book` that sends each visitor to the right meeting type, and **intake questions** per type whose answers are snapshotted on the booking.
- **Guest self-service** through a signed manage link: reschedule or cancel, and the calendar event moves or disappears with it.
- **Email automation** from a per-booking ledger: confirmation with the invite, reminders on editable offsets (24 hours and 1 hour by default), an optional follow-up after the call, and retry, pause and "mark held" controls for the owner.
- **A needs-attention view** for bookings that could not be written to iCloud yet, with a queued job retrying until they sync.

## What you get

- **Discovery.** Hand it the Apple ID and the app-specific password; it walks `current-user-principal` to `calendar-home-set` to the calendars, and tells you which ones hold events (Reminders lists are skipped).
- **Busy time.** A `REPORT` with a time range per calendar, recurring events expanded (RRULE, EXDATE), all-day events pinned to the owner's timezone, and the noise dropped: transparent events, cancelled events, and invitations the owner declined.
- **Writes.** `PUT` and `DELETE` with ETags, so a retry after a lost response never overwrites a write that worked.
- **A cache** that works on the file store (no `Cache::tags()`), with a generation key so one `bump()` forgets every range.
- **An event builder** that produces the four files a booking needs: the object you store on iCloud, and the REQUEST, CANCEL and PUBLISH files you email.
- **Two artisan commands** that answer "did I set this up right?" before you write any code.

## Install

The package is not on Packagist yet, so point Composer at the repository first:

```bash
composer config repositories.icloud-calendar vcs https://github.com/Justproof/laravel-icloud-calendar
composer require justproof/laravel-icloud-calendar:dev-main
php artisan vendor:publish --tag=icloud-calendar-config
```

## Get an app-specific password

iCloud will not take your real password over CalDAV, and you would not want it to.

1. Sign in at [account.apple.com](https://account.apple.com) with the Apple ID whose calendars you want. Two-factor must be on; it is required for app-specific passwords.
2. Sign-In and Security, then App-Specific Passwords, then the plus button. Name it after the app. Copy the `xxxx-xxxx-xxxx-xxxx` it shows you, because it shows it once.
3. Put both in `.env`:

```dotenv
ICLOUD_CALDAV_USERNAME=you@icloud.com
ICLOUD_CALDAV_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx
ICLOUD_CALDAV_TIMEZONE=America/Chicago
```

The username is the Apple ID itself (the primary email on the account), not an alias. The timezone is the one all-day events should be read in; iCloud stores them without one.

Changing the Apple ID password revokes every app-specific password. The package turns the resulting 401 into a `CalendarUnavailable` with "app-specific password" in the message, so your admin page can say what happened in words.

## Prove it works before writing code

```bash
php artisan icloud-calendar:calendars
```

```
+------+-----------+-----------------------------------------------------------+
| Name | Color     | Href                                                      |
+------+-----------+-----------------------------------------------------------+
| Work | #1BADF8FF | https://p45-caldav.icloud.com/123456/calendars/ABCD-EF12/ |
| Home | #FF2968FF | https://p45-caldav.icloud.com/123456/calendars/9876-5432/ |
+------+-----------+-----------------------------------------------------------+
```

The hrefs are what you store. They live on a `pNN-caldav.icloud.com` host that iCloud picks for the account, not on `caldav.icloud.com`, and the package follows that hop for you.

Then look at the busy time it would hand your slot generator, and compare it with the Calendar app:

```bash
php artisan icloud-calendar:busy --days=7
```

```
+----------------------------+------------------+----------+---------------------------+
| Start (America/Chicago)    | End              | Summary  | UID                       |
+----------------------------+------------------+----------+---------------------------+
| Mon Nov 2 09:00            | Mon Nov 2 09:30  | Standup  | weekly-standup@icloud.com |
| Tue Nov 3 10:00            | Tue Nov 3 11:00  | Dentist  | timed-event@icloud.com    |
| Thu Nov 5 00:00            | Fri Nov 6 00:00  | Offsite  | allday-offsite@icloud.com |
+----------------------------+------------------+----------+---------------------------+
3 busy block(s) from 2 calendar(s).
```

If a recurring event is missing a week you expected, check for an `EXDATE`; if a whole day is blocked, that is an all-day event doing its job.

## Use it

### Discover calendars

```php
use Justproof\IcloudCalendar\Facades\IcloudCalendar;

foreach (IcloudCalendar::discoverCalendars() as $calendar) {
    // $calendar->href, $calendar->displayName, $calendar->color
}
```

### Read busy time

```php
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Justproof\IcloudCalendar\BusyTimeCache;

$range = CarbonPeriod::create(CarbonImmutable::now(), CarbonImmutable::now()->addDays(14));

$busy = app(BusyTimeCache::class)->forRange($blockingHrefs, $range);

foreach ($busy as $block) {
    // $block->start and $block->end are CarbonImmutable in UTC
    // $block->uid, $block->summary
    $block->overlaps($slotStart, $slotEnd);
}
```

`forRange()` serves from cache for `busy_cache_seconds` (five minutes by default). Right before you confirm a slot, ask again with `fresh()`, ideally under a `Cache::lock()`, so two people tapping the same time cannot both get it:

```php
$busy = Cache::lock('bookings', 10)->block(5, fn () => app(BusyTimeCache::class)->fresh($blockingHrefs, $range));
```

After you write or delete anything, call `bump()` so the next read sees it:

```php
app(BusyTimeCache::class)->bump();
```

`status()` gives you `last_ok` and `last_error` for an admin connection line.

### Write an event

```php
use Justproof\IcloudCalendar\EventBuilder;
use Justproof\IcloudCalendar\Facades\IcloudCalendar;

$event = EventBuilder::make()
    ->uid($booking->ulid.'@example.com')
    ->summary('30-minute call: Ada and Example')
    ->between($booking->starts_at, $booking->ends_at)
    ->organizer(config('mail.from.address'), config('mail.from.name'))
    ->attendee($booking->email, $booking->name)
    ->description("Phone call\nReschedule: ".$manageUrl)
    ->location('Phone call: '.$booking->phone)
    ->sequence($booking->ical_sequence);

['href' => $href, 'etag' => $etag] = IcloudCalendar::putEvent($writeCalendarHref, $event->uid, $event->forCalendar());

// Store $href and $etag on the booking. To update later:
IcloudCalendar::putEvent($writeCalendarHref, $uid, $event->sequence(1)->forCalendar(), $etag);

// To remove:
IcloudCalendar::deleteEvent($href, $etag);
```

### Email the invite

```php
Mail::to($booking->email)->send(
    (new BookingConfirmed($booking))->attachData($event->request(), 'invite.ics', [
        'mime' => 'text/calendar; method=REQUEST; charset=utf-8',
    ])
);

// On cancel: $event->cancel() with the same UID and a higher sequence.
// For an "Add to calendar" button: $event->publish().
```

## The two rules that keep invites from doubling

We expected one invite in the test inbox and got two. Here is why, and what the builder does about it.

**iCloud schedules on its own.** Store an event with an `ATTENDEE` on it and iCloud sends that attendee an invitation from the Apple ID, as a courtesy you did not ask for. If you also email an `.ics`, the guest gets two invites with the same UID and different organisers, and Apple Mail and Gmail both complain. `forCalendar()` adds `SCHEDULE-AGENT=CLIENT` to every attendee, which tells iCloud the client is handling scheduling, so it stays quiet and your email is the one invite.

**ORGANIZER is the sending address.** On every file: REQUEST, the update, CANCEL. Mail clients check that the organiser matches the message's From and refuse to apply updates when it does not. `organizer()` is you, not the Apple ID.

The owner line: a guest gets one invite from your address, and when they cancel from your page, their calendar clears itself.

## Errors

Every network failure, timeout and non-2xx answer throws `Justproof\IcloudCalendar\Exceptions\CalendarUnavailable`, with `->status` (HTTP code or null) and `->isUnauthorized()`. It is never swallowed into an empty busy list: an empty list means "free all day", and a timeout does not.

Decide your own policy. On pcdrama.com, an unreachable iCloud at confirm time still accepts the booking from cached busy time, stores it as unverified, and a queued job retries the write; the owner sees it in a "needs attention" tab until it syncs.

## Testing your app

`CalDavClient` uses Laravel's `Http` client, so `Http::fake()` covers everything. The fixtures under `tests/fixtures/caldav/` are the shapes iCloud returns (a multistatus with a principal, a calendar home on a `pNN` host, a calendar list with a Reminders collection to skip, and a set of events that exercise recurrence, all-day, transparent, cancelled and declined). Copy them.

```bash
composer test
```

## Requirements

PHP 8.3+, Laravel 12 or 13, `sabre/vobject` ^4.5 (pulled in for parsing and recurrence expansion; CalDAV transport is plain `Http`).

## License

MIT.
