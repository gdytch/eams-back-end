# Attendee is per event; EventRegistration merges into it

An Attendee used to be per organization and joined events through `event_registrations`. We made Attendee one row per (event, person) and folded the registration fields (QR token, ID card, registered_by/at) into it, with a single global User per person. Each event needs its own territory, form answers and breakout picks, and past events' ID cards and reports must not change when a person edits a later profile. A per-event snapshot gives both without a registration-plus-overrides layer.

## Considered Options

- Keep per-organization Attendee and hang per-event data off `event_registrations`: rejected because nearly every new field (territory, answers, picks, completeness) is per event, so the registration would become the real profile under a misleading name.

## Consequences

- Identity fields (name, email, mobile, photo) are duplicated per event; edits write back to User, never to other events' Attendees.
- Existing data needs a one-time split of attendees into per-event rows (see the migration plan in `.wayfinder/event-scoped-registration`).
