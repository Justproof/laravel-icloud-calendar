# Changelog

## 0.1.0 (2026-10-15)

First release, extracted from the pcdrama.com booking flow.

- `CalDavClient`: discovery, busy time with recurrence expansion, ETag-aware PUT and DELETE.
- `BusyTimeCache`: per-range cache under a generation key, file-store friendly, with connection status.
- `EventBuilder`: stored object (`SCHEDULE-AGENT=CLIENT`), REQUEST, CANCEL and PUBLISH.
- `icloud-calendar:calendars` and `icloud-calendar:busy` commands.
