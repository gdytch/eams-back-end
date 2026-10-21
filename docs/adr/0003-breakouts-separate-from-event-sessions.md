# Breakouts are separate from event sessions

A breakout has a date and a time slot like an `EventSession`, so reusing `event_sessions` (as a 1:1 `breakouts` extension or a `kind` column) would have given QR check-in, the check-in window and `attendance_records` for free. We chose a separate `breakouts` table with its own date, start and end time and no link to `event_sessions`. In this app a session is an attendance period that every Attendee is checked in to, such as "Day 1 Morning Session" from 08:00 to 12:00. A breakout usually runs inside such a period (for example 09:00 to 10:00) and has its own, independent attendance that checks only the Attendees who picked it. Making a breakout a session would mix those two attendance meanings, so the breakout keeps its own room, capacity, speakers, topics, program tree and attendance.

## Considered Options

- Breakout is an `EventSession` with a 1:1 `breakouts` table: rejected, chosen as the separate model instead.
- Breakout columns and a `kind` flag on `event_sessions`: rejected for the same reason.

## Consequences

- Session code (roster, absent counts, reports, dashboards) must not be extended to breakouts by accident; it keeps assuming every Attendee is expected.
- Breakout check-in needs its own path, since `attendance_records` is keyed on `event_session_id`. How it is recorded is decided in the "One definition of absent, including breakouts" ticket.
- Breakouts may overlap sessions in time, and usually do. Overlap rules only compare an Attendee's breakout picks with each other.
- Checking in to a breakout does not check the Attendee in to the session around it, and the reverse; the two attendances are recorded independently.
