# Events are archived, never deleted

Deleting an event hard-deleted its sessions, attendees, attendance and program through DB cascades, losing reporting history. Events are now archived instead (`archived_at`/`archived_by`, separate from `status`): read-only, hidden from default lists, reports and exports still viewable, and only a super admin can archive or unarchive. There is no delete endpoint, for any role.

## Consequences

- Every event-scoped write path must check the archive state; this is enforced in the policy layer (`Event::isArchived()` returning a 403 "This event is archived.").
