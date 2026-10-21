# Event-scoped registration spec

Implementation-ready spec for six changes: per-event Attendees, event archiving, hiding attendee-only users, breakouts, registration forms (shirt sizes with payment proof), and a strict multi-step registration wizard. It consolidates the decisions made during planning (2026-10-21 to 2026-10-22). Build issues in `gdytch/eams-back-end` and `gdytch/eams-front-end` link to the sections below.

Vocabulary follows [`GLOSSARY.md`](../../GLOSSARY.md). Hard-to-reverse choices are recorded as ADRs:

- [0001 Attendee is per event](../adr/0001-per-event-attendee.md)
- [0002 Events are archived, not deleted](../adr/0002-events-archived-not-deleted.md)
- [0003 Breakouts are separate from event sessions](../adr/0003-breakouts-separate-from-event-sessions.md)
- [0004 Verified email links Attendees](../adr/0004-verified-email-links-attendees.md)

## How the work is delivered

### Releases

| Release | Content | Maintenance window |
| --- | --- | --- |
| 0 | Archiving | No |
| 1 | Attendee migration, verified-email linking, hidden attendee-only users, single-step registration on the per-event Attendee | Yes |
| 2 | Registration core: territory rules, churches, completeness, public wizard, admin wizard, banners, mail | No |
| 3 | Breakouts and the shared Present / Absent / Upcoming rule | No |
| 4 | Form builder | No |

- No feature flags. Breakouts and forms are opt-in per event (nothing changes until an admin adds a breakout or publishes a form), and that is the gate.
- Each release deploys both repos the same day: backend first with a backward-compatible API, frontend right after.
- No release during an event's session days or in the 48 hours before them. Checkers sync offline scans before Releases 1 and 3.
- Before every release: DB snapshot and storage backup. Release 1 rolls back by snapshot restore only. Releases 0, 2, 3 and 4 add schema without destroying data; their migrations get working `down()` methods, and rollback is a code revert plus `down()`.
- No staging environment. Release 1's migration and Release 2's backfill and recompute are rehearsed on a local Docker stack loaded with a production DB and storage copy. Other releases are tested locally on seed data.
- Between Release 1 and Release 2 there is no date target. The single-step registration keeps working in that gap.

### Build issues

- One milestone per release in each repo.
- A backend slice comes before the frontend slice that uses it; the frontend issue is blocked by the backend issue.
- Each slice fits one agent session and is testable alone.
- `AMS-backend/openapi.yaml` is canonical. Every backend slice that changes the API updates it. A frontend slice copies the backend file whole when it starts.
- Backend tests: PHPUnit feature tests for every endpoint, policy and job a slice adds; `phpstan` passes.
- Frontend tests: `node:test` unit tests for logic placed in `src/utils`, run with `npm test`, plus a manual browser check. No component test framework.
- Each release has a HITL runbook issue (snapshot, deploy order, smoke checks, rollback). Releases 1 and 2 also have a rehearsal issue.

## Release 0: Archiving

Source decision: "Archive enforcement points". ADR 0002.

### Storage and actions

- New nullable `events.archived_at` and `events.archived_by`, separate from the `status` enum (draft, published, completed, cancelled). Unarchive clears both; the event keeps its previous status.
- Only `super_admin` archives and unarchives. Org admins see the "Archived" badge and filter and get read-only views. No "request archive" flow.
- An event can be archived in any status, after a confirm dialog. No auto-archive.
- `DELETE events/{event}` is removed, not repurposed.
- Archive and unarchive are audited (actor, timestamp, event).
- Event lists hide archived events by default behind an "Archived" filter.

### Write block

An archived event blocks every write to the event or its children:

- event update and uploads (ID card background, banner)
- speakers, sessions, program and program items, breakouts (Release 3)
- registration, including admin registering on behalf
- attendee edit, photo, duplicate merge
- forms and form answers (Release 4)
- ID card regenerate
- attendance scan, manual, check-out and sync-batch
- granting checker access (`users/{user}/event-access`)

Reads stay allowed: reports, exports and the ID card grid download. Unarchive is the only write allowed.

Enforcement is in the policy layer only. An `Event::isArchived()` check runs in the write abilities of every event-scoped policy (Event, EventSession, EventProgram, Attendee, AttendanceRecord, and later Breakout and Form) and returns `Response::deny('This event is archived.')`, a 403 with a stable message. No middleware, no model guard. Each blocked endpoint gets a feature test.

Exception: a church merge (Release 2) sets `church_id` to null on archived events' Attendees.

### Read side

- Offline sync-batch: scans that sync after archive are rejected per scan with an "event archived" result, even if scanned before archive. The client clock is not trusted.
- `/e/:inviteToken` for an archived event shows a "This event has ended / is closed" page with registration hidden. No 404.
- An attendee-only user whose events are all archived still logs in. The dashboard lists past events read-only (ID card view and download, attendance history) under a "no upcoming events" empty state.
- Checker assignments to an archived event are kept, but the event is hidden from the checker's event picker and scanner until unarchive.
- Jobs already running at archive time (ID card generation, grid downloads, exports) finish normally.

## Release 1: Attendee migration

Source decisions: "Event-scoped attendee model", "Migrating existing attendees to per-event profiles", "Admin registers on behalf, CSV import removed" (linking rule only). ADRs 0001 and 0004.

### Model

- `EventRegistration` merges into `Attendee`. One `attendees` row per (event, person). `attendees` gains `event_id`; `qr_token`, `id_card_path`, `id_card_images_path`, `id_card_generated_at`, `registered_by` and `registered_at` move onto it. `event_registrations` and the `EventRegistration` model are removed.
- Each Attendee holds its own copy of name, email, mobile and photo, plus territory, remarks, and later answers and picks. Past events never change when a later profile is edited.
- A new event's registration prefills from the User's most recent Attendee, else from `User`. Edits to name, mobile and photo write back to `User`, never to other events' Attendees. When `user_id` is set, the Attendee email mirrors the User email and is read-only.
- Unique indexes: `unique(event_id, user_id)` (NULLs allowed), `unique(event_id, email_address)` with nullable email, `qr_token` globally unique. The global unique indexes on `attendees.user_id` and `attendees.email_address` are dropped. A duplicate merge nulls the source row's `user_id` and `email_address`.
- Attendee-only users have `users.organization_id = null`. `attendees.organization_id` stays for the `BelongsToOrganization` scope and is set from `event.organization_id` in a model `saving` hook, never from client input.
- Duplicate merge stays, scoped to one event: name matching and dismissals compare Attendees within the same event only.
- `AttendeeExport` requires `event_id`.

### Data migration

1. For a person with N registrations, the earliest registration's event keeps the original `attendees` row (same id). Each other registration gets a new row copied from the original (name, email, mobile, photo, territory, organization level, remarks). Every row gets a nullable unique `legacy_registration_id` holding its source `event_registrations.id`. The column stays permanently.
2. Attendees with no registrations are exported to a CSV on the `local` disk, then deleted. The dry run reports their count first.
3. Merged sources (`merged_into_id` set) are deleted. `attendee_duplicate_dismissals` pairs are remapped once per event where both people have a profile; other pairs are dropped.
4. Photos are copied per new row to `attendees/{newId}/photo-*.webp`, never shared. Legacy photos on the `public` disk move to `local` during the copy.
5. QR token, ID card paths, `id_card_generated_at`, `registered_by` and `registered_at` move unchanged. Printed badges keep working. Existing PDF files stay where they are. No regeneration.
6. `attendance_records.event_registration_id` is renamed to `attendee_id` and remapped through `legacy_registration_id`; new `unique(attendee_id, event_session_id)`.
7. `event_program_items.attendee_id` is remapped to the person's profile in the program's own event, else nulled with `participant_name` keeping the text.
8. `audit_logs` stay untouched. Existing `id_card_grid_downloads` rows and their files are deleted.
9. Every `role = attendee` user gets `organization_id = null`.

Checks for the dry run on a production copy: registrations count equals new attendee rows, attendance count unchanged, every `qr_token` still resolves, count of deleted unregistered Attendees.

Compatibility: until Release 2, `sync-batch` and manual attendance still accept an old `event_registration_id` and translate it through `legacy_registration_id`.

### Linking rule and accounts

- A User links to Attendees without a login only once its email is verified; SSO and using an invite link count as verified. On verification the User links every Attendee without a login with that email, across all events, skipping events where the User already has an Attendee. Each link is audited. This replaces the `attendee.invite_claimed`, `attendee.email_merged` and `attendee.sso_merged` paths in `AuthController` and removes the unverified email match at password sign-up.
- `auth/register` and SSO create the User and run the link only. They no longer create an Attendee or set `users.organization_id`.
- QR claim (`public/attendee-claims/*`) stays, pointed at the single per-event Attendee behind the scanned `qr_token`, and only for Attendees with no email. The emailed code verifies the new email, then the linking rule connects it.
- `invite_token` / `invited_at` stay per Attendee with the 30-day expiry (`config/auth.php` `invite_expire`). Rows copied by the migration start with no token. Using any invite link verifies the email and links all of that email's Attendees.
- The Users page and API hide `role = attendee` users entirely, with no toggle. Staff who also attend keep their staff role.
- The existing single-step registration creates a per-event Attendee, sets `registered_at` at once and generates the ID card as today. The welcome mail stays disabled.

Code touched: `AuthController.php` (register, SSO, QR SSO), `AttendeeQrClaimController`, `AttendeeQrClaimService`, `AttendeeInvitationService`, `AttendeeAccountInviteMail`; 28 files under `app/` use `EventRegistration`. Frontend: routes `accept-invite`, `attendee-claim`, `/e/:inviteToken`; pages `Register`, `AcceptInvite`, `AttendeeClaim`, `EventLanding`.

## Release 2: Registration core

Source decisions: "Territory requirements by organization level", "Registration draft and completeness state", "Registration wizard UX", "Admin registers on behalf, CSV import removed", "Registration and breakout notifications". Ships as one release because each part depends on the others.

### Territory and churches

- Union-level Attendees answer the union only; mission and church are hidden and stored null. Mission-level Attendees must answer union, mission and church.
- The server enforces that the mission belongs to the union and the church to the mission, in the wizard and in admin forms. Dropdowns list only the event organization's unions, missions and churches.
- The `unique(mission_id, name)` index and matching `StoreChurchRequest` rule are dropped. A church is identified by normalized name plus normalized address within one mission (case-insensitive, trimmed, whitespace collapsed), checked in the app. `address` is required whenever a church is created or edited. Existing rows with null address stay valid.
- "My church isn't listed": mission-level Attendees only; the church attaches to the chosen mission; name and address required. Duplicate check across verified and unverified churches in that mission:
  - same name and address: blocked, the existing church is offered instead;
  - same name, different address: allowed after "A church with this name exists at <address>. Is this yours?";
  - similar name: soft "Did you mean…?" warning, creation allowed.
- New `churches.verified_at` and `verified_by`. Attendee-created churches take effect at once with no label for Attendees; only admins see the unverified flag. All existing churches start unverified. Churches created by org_admin or super_admin are verified on creation.
- Churches page "Unverified" filter, for org admins of the organization and super_admin:
  - Verify (blocked until the church has an address);
  - Edit name, address or mission;
  - Merge into another church in the same mission. The merge repoints `church_id` on Attendees of non-archived events, nulls it on archived events' Attendees, deletes the source, and is audited.

### Completeness

- Drafts are real Attendee rows. The row is created when Basic info is first saved; every later step writes straight to the real tables.
- A single `RegistrationCompleteness` service owns the rule. Its result is cached on the Attendee: `registration_completed_at` (current state, cleared when incomplete) and `first_registered_at` (set once, never cleared). It recomputes on every relevant write.
- Fully registered requires: basic info (name, email, mobile; no photo), territory per level, the pick rule met when breakouts are enabled (Release 3), every published required form's required fields answered and every started optional form's required fields answered (Release 4), and the Attendee's explicit Finish confirmation on Review. The confirmation is cleared whenever the Attendee becomes incomplete.
- Draft Attendee (never fully registered): no QR token, no ID card. A checker scan shows "registration incomplete" and cannot admit. Dashboard shows only a "Finish registration" banner. Admin lists hide drafts by default behind an "Incomplete" filter. Never Expected, left out of attendance reports.
- Once registered, now incomplete: keeps QR token and ID card; check-in works with a soft "incomplete" note to the checker; stays Expected; dashboard asks them to finish.
- ID cards are generated on first full registration, carry no photo, and regenerate automatically when card-visible data (name, territory) changes.
- Admin changes that tighten requirements (enabling breakouts, publishing a required form, adding a required field, making a field required) dispatch a queued job that recomputes every Attendee of that event. Before saving, the admin sees "This makes N registrants incomplete. They will see a banner on their dashboard; no email is sent." N comes from a dry run of the rule.
- Resume: the server returns per-step completeness; the wizard opens at the first incomplete step. Nothing in browser storage.
- Counts and the daily chart (`DashboardRegistrationInsights`) use `first_registered_at`. The event dashboard shows the Incomplete count split into Draft and once-registered.
- org_admin and super_admin may delete a Draft Attendee (audited, frees seats). Once-registered Attendees cannot be deleted this way.
- Same User on two devices resumes the same draft (`unique(event_id, user_id)`).

Deploy: `registered_at` is backfilled into `registration_completed_at` and `first_registered_at`; then, after a dry-run count, completeness is recomputed for open events only (mission-level Attendees without a church become incomplete). Past and archived events are not re-checked. The `event_registration_id` shim is removed.

### Public wizard

- `/e/:inviteToken` keeps the account choices (Google, email sign-up, log in). After sign-in the redirect lands on the wizard page.
- Single page with accordion sections in order: Basic info, Territory, Breakouts (Release 3, if enabled), one section per published form (Release 4), Review. Finished sections collapse to a summary with Edit. Sections past the first unfinished one are visible but locked ("Finish earlier steps first"). Numbered section dots carry progress. Replaces `EventRegistrationDialog.vue`.
- Each "Save and continue" is one server save. On return the page opens the first incomplete section with a "Welcome back" toast.
- While not fully registered, any finished section reopens through Edit or Back.
- Review summarises every answer with Edit links and a "Finish registration" button, warning that breakout picks lock after it.
- Confirmation: "You're fully registered", QR code shown at once, a note that the ID card appears on the dashboard in a few minutes, picked breakouts, "Go to dashboard".
- No photo upload anywhere in registration.
- Before the wizard opens, if an Attendee without a login in this event has the User's email and the email is unverified, the person sees "Verify your email to continue". After verifying, the link connects them and the wizard opens on that Attendee.

### Admin wizard (register on behalf)

- Opened from the event's Attendees page. Same sections without the account section, same strict completeness. org_admin and super_admin only. Blocked on archived events.
- No User is created. `user_id` null, email optional. If the email belongs to a User, the Attendee links to it (email read-only, prefill from that User's latest Attendee); if that User already has an Attendee in the event, the admin is sent to it.
- The admin fills every answer, including file fields, and presses Finish. Admin drafts follow the normal draft rules.
- Same-event name duplicate check as a "possible duplicate, save anyway" warning on Basic info save.
- `registered_by` is the admin; audited as on-behalf. After Finish, admins may still edit basic info, territory and answers (audited).
- "Resend invite" (audited) on an Attendee without a login that has an email issues a fresh token.
- Replaces `AttendeeController::store` and its `auto_register` path. No CSV import exists or is built.

### Banners and mail

- Incomplete after an admin change: a non-dismissible banner on the Attendee dashboard (one per affected event) and on top of the wizard. It names each missing item ("Pick your breakouts", "Answer the Shirt order form", "Add your church") with a "Finish registration" button. Once-registered Attendees also see "Your QR code and ID card still work." No email.
- `EventRegistrationWelcomeMail` is re-enabled and sent once, when `first_registered_at` is set: event name, dates, venue and a dashboard link. No QR, no attachment.
- Admin-registered Attendee with an email and no login: one merged mail at first full registration (welcome content plus the invite link). A draft the admin never finishes sends nothing. If the email belongs to a User, the plain welcome mail goes out. No email, no mail.
- All mail is queued on the database queue with a shared configurable rate limit (default about 20 per minute) and retries. Welcome and invite mails use a normal-priority `mail` queue; auth mails (verify email, reset password, QR claim code) use a high-priority queue. Failed sends are logged only.

Code touched: commented-out welcome sends in `EventRegistrationController.php:123-127` and `AttendeeController.php:139-142`; mailables in `app/Mail/`; notifications in `app/Notifications/`; scheduler in `routes/console.php`.

### Exports in this release

- The attendee list export includes everyone with a registration status column (Fully registered / Incomplete / Draft) and gets a status filter.

## Release 3: Breakouts

Source decisions: "Breakout model and pick rules", "One definition of absent, including breakouts", "Registration wizard UX" (Breakouts section), "Registration and breakout notifications", "Reports and exports for breakouts and forms". ADR 0003.

### Breakout entity

- New event-scoped `breakouts` table (`BelongsToOrganization`): title, description, date, start time, end time, room (required free text, no clash check), capacity (nullable = unlimited, else at least 1), ordered topics (free text, at least one). No link to `event_sessions`.
- Many-to-many ordered speakers from the same event's `speakers`. Zero allowed. Deleting a speaker detaches it.
- Optional program tree via nullable `event_programs.breakout_id` (one program per event plus one per breakout). Its single Day equals the breakout date. Visibility follows the event program's public setting.
- Times are event-local (`Asia/Manila`) and cannot cross midnight.
- Breakout policy includes the archive write check.
- Admin list shows `picks / capacity`, an "Overbooked" badge when picks exceed capacity, and an overbooked filter. The event dashboard shows the overbooked count. No notification.
- Breakouts show in the wizard and the Attendee dashboard; on the public landing page only when the event program is public.

### Pick rules

- Two slots overlap only when on the same date and `startA < endB && startB < endA`. Touching boundaries do not overlap. Only one Attendee's picks are compared with each other; breakouts may overlap sessions.
- Event pick setting: mode "one" (exactly 1 pick) or "multiple" with `min` (default 1, 0 allowed) and optional `max`, `0 <= min <= max`. Breakouts are enabled when the setting is set. Cannot be enabled with zero breakouts, cannot be disabled while picks exist. Saving is blocked when `min` exceeds the largest set of mutually non-overlapping breakouts; a breakout time edit or delete that would push it below `min` is blocked. Loosening is always allowed; tightening or switching mode is blocked when any Attendee's current picks would break it.
- Enabling breakouts for the first time after registration opened makes existing registrants incomplete (recompute with dry-run confirm).
- A pick holds a seat as soon as the Breakouts section is saved, including in a draft. While not fully registered the Attendee changes picks freely.
- Saving picks runs in one transaction that locks the chosen breakout rows `FOR UPDATE` in id order, counts picks and rejects with a 422 naming each full breakout. All-or-nothing. A pick the Attendee already holds never counts against them.
- No-choice overbooking: when an Attendee cannot reach the required count with open breakouts that fit their picks, the server allows exactly the shortfall to go to full breakouts, inside the same locked transaction. Overlap is never relaxed. The Attendee sees a note.
- Picks are final once fully registered. Then only an event admin or super_admin changes them, at any time, audited. Admins respect count and overlap but may exceed capacity and may lower capacity below picks.
- Deleting a breakout with picks is blocked. A time change that would make any Attendee's picks overlap is blocked. Other edits are always allowed.
- A daily job drops the picks of Draft Attendees whose last save is more than 24 hours old and sets `picks_released_at`. The wizard shows "Your earlier picks were released after 24 hours; seats may have changed" until the next Breakouts save clears it. Once-registered Attendees are never touched.

### Wizard Breakouts section

Picks grouped by time slot. Each card shows title, speakers, room, topics and seats left ("Open" with no capacity; amber at 5 or fewer). Full breakouts are greyed and labelled Full. Breakouts overlapping a current pick are disabled with "Overlaps your pick ...". A sticky counter shows picks against the rule. A note says seats are held on save and released after 24 hours idle. A lost capacity race shows an error naming the breakout, keeps other picks and flips the card to Full. No-choice unlocked breakouts have a dashed border and "opened for you because you have no other choice". Admins in the admin wizard may pick Full breakouts after a confirm step.

### Attendance: one rule for absent

- For one Attendee and one slot (session or breakout): Present when a check-in exists; Absent when the slot has ended with no check-in; Upcoming otherwise. Absent is never stored. One shared rule (model method plus matching query scope) replaces `AttendanceController::roster`, `ReportController` (session stats and summary), `AttendeeController`, `AttendeeDashboardData` (both row builders), `EventSessionRosterEntryResource` and the attendance exports. The live roster filter becomes "present / not checked in".
- Expected at a session: first fully registered before the session ended. Expected at a breakout: the same, limited to current pick holders.
- `attendance_records.event_session_id` becomes nullable, nullable `breakout_id` added, check constraint requires exactly one. Uniqueness (attendee, session) and (attendee, breakout).
- Breakouts use the event's `check_in_window_minutes` and the admin early override. For sessions and breakouts, checkers check in only until the slot ends; after that only an event admin or super_admin records a late check-in, audited `attendance.checked_in_late`.
- Walk-in: an Attendee of the event with no pick, admitted only by an event admin or super_admin. Record has `is_walk_in = true` and `recorded_by`, audit `attendance.walk_in`. No pick created. Never expected. A QR from another event is always rejected.
- No breakout check-out.
- Attendance rate: Present ÷ (Present + Absent) over expected ended slots, sessions and breakouts reported separately. The overall attendance rating stays sessions-only.
- A breakout check-in (walk-ins included) also checks the Attendee in to every same-date session overlapping the breakout (half-open), only when that actor could check in to that session directly at that moment. The record keeps the scan's `method` and `recorded_by`, gets a nullable `via_breakout_id`, and is audited `attendance.checked_in_via_breakout`. Runs server-side in the same transaction for live scans and sync-batch; the earliest `check_in_at` wins. A session check-in never creates breakout check-ins.

### Reports and exports

- Reports "Breakouts" tab: every breakout with Capacity, Picks, Overbooked badge, Expected, Present, Absent, Walk-ins and Rate. Drill-down roster: Attendee, territory, status (Present / Absent / Upcoming / Walk-in), check-in time, method, recorded by.
- `EventAttendanceExport` adds one breakout overview sheet plus one sheet per breakout after the session sheets. The PDF report gets the same sections.
- Via-breakout session check-ins count as ordinary Present; session roster, sheet and PDF get a "Via breakout" column.
- Every sheet (`EventOverviewAttendanceSheetExport`, `EventSessionAttendanceSheetExport`, `AttendanceSheetExport`) and the PDF use the shared rule, fixing `default => 'Absent'`. "Checked Out" stays sessions-only.
- `AttendeeSummaryExport` keeps sessions-only Present / Absent / Attendance Rating and adds Breakout Present / Absent columns when the event has breakouts.
- Attendance reports and exports include only Expected Attendees plus walk-ins.
- Client-side CSVs in `Reports.vue` and `OrganizationOverview.vue` follow the same rule; the overview counts Attendees.
- Breakout exports are audited `breakout_attendance.exported` and stay on `viewStaffData`.

## Release 4: Form builder

Source decisions: "Dynamic form builder approaches", "Dedicated shirt form or built-in form builder", "Registration wizard UX" (form sections), "Reports and exports for breakouts and forms".

### Authoring

- Normalized tables: forms, fields, options, submissions, answers, with stable field and option ids.
- org_admin (own organization's events) and super_admin author forms. Checkers cannot. Form policy includes the archive write check.
- Field types: short text, long text, single choice (dropdown or radio), multiple choice (checkboxes), number, file upload. Every field has a label, optional help text and a required flag; choice fields have ordered options.
- Each form has a required flag and a draft/published state. Only published forms appear to Attendees. Each form is its own wizard section after Breakouts, in admin order.
- Editing after answers exist:
  - always allowed: labels, help text, option labels, reorder, add optional fields or options;
  - allowed but makes existing submitters incomplete (recompute with dry-run confirm): add a required field, make a field required;
  - removing an answered field or option archives it; old answers stay and export as "(removed)"; archived options cannot be picked;
  - a field's type cannot change once it has answers;
  - a form with no answers can be freely edited or deleted.
- Copy a form from another event in the same organization as a new unlinked draft.

### Answers

- FormRequest rules are generated from field definitions.
- A required form blocks full registration until its required fields are answered. An optional form can be skipped; once started, its required fields apply.
- The Attendee edits answers while incomplete. Once fully registered only admins change them (audited).
- File answers: one file per field, PNG, JPEG or PDF, at most 5 MB, validated by `ValidImageUpload` extended to accept PDF. Stored on the private `local` disk and streamed through a policy-checked controller like `ProfilePhotoController`. Viewable by the owning Attendee, org_admin of the event's organization and super_admin. Re-upload replaces with no history.
- Payment proof is an ordinary file field labelled by the admin. No review, approve/reject or payment status.

### Wizard form sections

Generic `DynamicField` renderer for the six field types. Optional forms have "Skip this form" and show "skipped" in their summary. File fields are a single drop zone; an uploaded file shows name and size with Replace. Same sections in the admin wizard.

### Cleanup after archiving

A daily scheduled command deletes form upload files and generated ID card files (`id_card_path`, `id_card_images_path`) of every event archived at least 15 days ago. Unarchiving within 15 days prevents deletion; archiving again restarts the clock. The archive confirm dialog states the deletion date. Answer rows keep the original filename and get `file_deleted_at`, show "File deleted (event archived)", and still count as answered. Deleted ID cards clear `id_card_generated_at`. Profile photos are not deleted.

### Form answers export

Separate xlsx per event, one sheet per published form, one row per Attendee with a submission. Fixed columns: name, email, territory, registration status; then one column per field in form order; archived fields last with "(removed)" headers. Multiple choice joined with "; ". File answers show the filename as a hyperlink to the policy-checked download route. Deleted files show "File deleted (event archived)". An unstarted optional form gives an empty sheet with headers. No PDF. Restricted to org_admin of the event's organization and super_admin; audited `form_answers.exported`.

## Out of scope

- Breakout waitlist.
- Payment proof review, approve/reject or payment status.
- Form per-option pricing or stock, conditional logic, date fields, shared form templates.
- Bulk attendee entry (CSV or spreadsheet import).
- Feature flags and a staging environment.
