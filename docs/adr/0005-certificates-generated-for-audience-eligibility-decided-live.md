# Certificates are generated for the whole Audience on publish; Eligibility is decided live

When a Certificate is published, a background job generates an Issued certificate for every Fully registered Attendee in its Audience and stores the file. Whether an Attendee may see or download it is decided live, when they ask, from the release time, the rule and their current Attendance rate. We chose this over generating at release time for only the Eligible Attendees. Release then needs no generation wait, an Attendee who becomes Eligible sees the file immediately, and the dashboard progress bar covers a single publish run. The cost is storing files for people who may never qualify, which the retention purge limits.

## Considered Options

- Generate at release time for the Eligible only: rejected. Release would wait on a large batch, and late-qualifying Attendees would wait for another run.
- Generate on demand when an Eligible Attendee asks: rejected. Slow first download, and no progress to show the admin.

## Consequences

- Eligibility is never stored, so a rule or release-time change takes effect at once and needs no regeneration.
- The design (Template, name position, font, color, page setup) is locked while published, because every stored file depends on it. Changing it means Unpublish, edit, Publish, which regenerates everything.
- Audience changes (a newly Fully registered Attendee, a Territory change) add missing Issued certificates; Attendees who leave the Audience keep their file, hidden.
- Issued certificate files are deleted `Event::FILE_RETENTION_DAYS` after the event is archived, like ID cards. The row, Template and font stay, so unarchive plus Regenerate restores them.
