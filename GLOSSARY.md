# EMS (Event Management System)

Organizations run events; people sign up through an invite link and are checked in by QR scan. This glossary is canonical for both `AMS-backend` and `AMS-Frontend`.

## People

**User**:
The single global login for one person, whether by password or SSO. One person has exactly one User.
_Avoid_: Account holder, member

**Staff**:
A User whose role is not attendee (super admin, org admin, or checker).
_Avoid_: Admin (when meaning any staff role)

**Attendee**:
One person's profile in one event, holding that event's identity snapshot, territory, answers and picks. A person who joins three events has three Attendees.
_Avoid_: Participant, registrant, member, event registration

**Linked Attendee**:
An Attendee connected to the User login of the person it describes. An Attendee an admin registered on someone's behalf has no login until that person claims it.
_Avoid_: Claimed attendee, account attendee

**Claim**:
Connecting a User to Attendees that have no login, which happens only after the person proves they own the Attendee's email (verified email, SSO, or the invite link), or through the ID card QR code when the Attendee has no email.
_Avoid_: Merge (reserved for duplicate merge), link-up

## Registration

**Registration**:
The act of signing up for an event through the registration wizard. The resulting record is an Attendee, not a registration.
_Avoid_: Using "registration" for the record

**Fully registered**:
An Attendee who meets every requirement the event currently sets (basic info, territory, breakout picks when breakouts are enabled, and answers to required forms) and has confirmed with "Finish registration". The confirmation is cleared whenever the Attendee becomes incomplete.
_Avoid_: Complete registration, confirmed

**Incomplete Attendee**:
An Attendee who is not fully registered, whether they never finished or the event added a requirement after they finished.
_Avoid_: Pending, partial

**Draft Attendee**:
An Incomplete Attendee who has never been fully registered.
_Avoid_: Unregistered, abandoned registration

## Forms

**Form**:
A set of extra questions an event asks during registration, such as shirt size, answered in its own wizard step. A form is either required or optional for full registration.
_Avoid_: Survey, questionnaire, registration form (for the whole wizard)

**Answer**:
An Attendee's response to one field of a form, which may be an uploaded file.
_Avoid_: Response, submission value

**Payment proof**:
An ordinary file answer that an admin labelled "Payment proof". Nothing reviews or approves it, and there is no payment status.
_Avoid_: Receipt, payment status, approved payment

## Territory

**Territory**:
Where an Attendee belongs in the organization's structure: a union, and for mission-level Attendees also a mission and a church.
_Avoid_: Location, region, address

**Organization level**:
Whether an Attendee represents a union or a mission. Union-level Attendees have a union only; mission-level Attendees have union, mission and church.

**Union**:
The top division of an organization, containing missions.

**Mission**:
A division of a union, containing churches.

**Church**:
A local congregation within one mission, identified by its name together with its address.
_Avoid_: Congregation, local church

**Unverified church**:
A church that no admin has confirmed yet, such as one an Attendee added during registration. Selectable like any other church.
_Avoid_: Pending church, draft church

## Event lifecycle

**Completed event**:
An event that has taken place. Still editable unless archived.

**Cancelled event**:
An event that will not take place. Still editable unless archived.

**Archived event**:
A read-only event hidden from default lists, independent of whether it is completed or cancelled. Events are archived, never deleted.
_Avoid_: Deleted event, closed event

## Program

**Session**:
An attendance period of an event, such as a morning or afternoon of one day, that every Attendee is expected at and is checked in to by QR scan.
_Avoid_: Main session, slot

**Breakout**:
An optional talk or workshop within one event, with its own time slot, room, speakers, topics and attendance, checked only for Attendees who picked it. A breakout usually runs inside a session but is not part of it; a workshop repeated at two times is two breakouts.
_Avoid_: Breakout session, workshop, track, elective

**Pick**:
An Attendee's choice of one breakout. Picks are final once the Attendee is fully registered; only admins change them after that.
_Avoid_: Selection, enrollment, booking

**Full breakout**:
A breakout whose picks have reached its capacity; it closes for new picks unless an Attendee has no other choice. There is no waitlist.
_Avoid_: Sold out, closed breakout

**Overbooked breakout**:
A breakout with more picks than its capacity, because an Attendee had no other way to reach the required number of picks or an admin added picks on purpose. Admins resolve it; nothing resolves it automatically.
_Avoid_: Overflow, oversold

## Attendance

**Slot**:
The time span of one session or one breakout, used when the same attendance rule applies to both.
_Avoid_: Period, block

**Expected Attendee**:
An Attendee who should be at a slot: for a session, anyone first fully registered before it ended; for a breakout, the same but only those who currently pick it.
_Avoid_: Registered (for this meaning), invitee

**Present**:
An Attendee with a check-in for a slot.
_Avoid_: Attended, checked (alone)

**Absent**:
An expected Attendee with no check-in for a slot that has ended. Never stored; always derived.
_Avoid_: No-show, missing

**Upcoming**:
An expected Attendee with no check-in for a slot that has not ended yet.
_Avoid_: Pending, not yet occurred

**Walk-in**:
An Attendee admitted by an admin to a breakout they did not pick. A walk-in is present but never expected, so never absent.
_Avoid_: Override, guest, extra

## Certificates

**Certificate**:
An admin-designed e-certificate of one event, such as a certificate of attendance or participation: a background template, a positioned attendee name, an audience and a release rule. An event can have several. A Certificate is a draft until published.
_Avoid_: Template (the template is only its background image), diploma, award

**Template**:
The background image of a Certificate. Fixed in size; only the attendee name is placed on it.
_Avoid_: Background (alone), design

**Issued certificate**:
The generated file of one Certificate for one Attendee, carrying that Attendee's name. It exists as soon as its Certificate is published, before the Attendee is eligible to see it.
_Avoid_: Certificate copy, generated certificate

**Audience**:
The Fully registered Attendees a Certificate is generated for: those matching the selected unions and missions by Organization level, plus any Attendees the admin picked by name. With nothing selected, every Fully registered Attendee of the event. Selecting a union covers union-level Attendees of that union; selecting a mission covers mission-level Attendees of that mission.
_Avoid_: Recipients, target group

**Attendance rate**:
An Attendee's Present slots divided by their Expected slots that have ended, capped at 100%. Sessions always count; breakouts count only when the Certificate says so.
_Avoid_: Attendance percentage, score

**Eligible**:
An Audience member who meets the Certificate's release rule right now: Everyone (fully registered), Checked in at least once, or a minimum Attendance rate. Decided live, never stored. Attendees picked by name are always Eligible once Released, whatever the rule.
_Avoid_: Qualified, entitled

**Released**:
A Certificate whose release date and time has passed, so Eligible Attendees can see and download their Issued certificate.
_Avoid_: Live, visible
