# Only a verified email links a login to an Attendee

Admins can register a person without creating a login, so an Attendee may exist with `user_id` null and an email address. Before this decision, three paths linked such Attendees to a User: password sign-up matched them by email without verifying it (`attendee.email_merged`), SSO matched them by the provider's email (`attendee.sso_merged`), and the invite link matched them by token (`attendee.invite_claimed`). The password path let anyone who typed someone else's email take over that person's Attendee, including their QR code and form answers. We chose one rule: a User is linked to Attendees without a login only once the User's email is verified. SSO and using the invite link count as verification. On verification, the User is linked to every Attendee without a login that has that email, across all events, except events where the User already has an Attendee. The ID card QR claim stays as the only other path, and only for Attendees with no email; its emailed code verifies the new email before linking.

## Considered Options

- Keep matching by unverified email at sign-up: rejected, allows takeover.
- Link only the one Attendee named by an invite token: rejected, leaves a person with several no-login Attendees (one per event) to claim each separately.
- Drop the QR claim entirely: rejected, walk-ups with no email would have no way to get a login.

## Consequences

- Sign-up and SSO only create the User and run the link; Attendees are created only by a registration wizard save.
- A person with an unverified email who opens an event where an admin already registered their email must verify before the wizard opens, so no duplicate Attendee is created.
- Every link is audited, and the three old audit actions become one.
