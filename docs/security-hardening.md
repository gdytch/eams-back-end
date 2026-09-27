# Security hardening implementation

## Scope and accepted risks

The implementation addresses the audit findings except #5 (public event invitation email-based attendee claiming) and #6 (QR password claiming without verification of an existing email address). The owner explicitly accepted these two risks to support attendees whose records were entered by a secretary and who may not have access to email.

The existing ID-card flow remains: scan the full stored QR URL, provide first/last name, union and mission, then complete the claim. `No mission` continues to mean a null mission. No global email-verification middleware or new email-ownership requirement was added to these accepted flows. Search-engine exclusion remains enabled. Search-engine exclusion does not provide access control.

## Changes

- Organization administrators cannot create, invite or promote a super administrator. They also cannot change a super administrator through the ordinary update policy.
- Staff creation requests reject another organization's ID. Claiming an existing attendee preserves the attendee's organization rather than accepting a replacement tenant from the request.
- Attendees cannot use staff attendance writes, event rosters, exports or staff reports. Attendee profile reads require ownership. Own ID-card downloads remain available, including registrations in another organization; mismatched event and registration IDs are rejected.
- Only administrators can change checker event assignments. Existing semantics are preserved: no assignments means all events in the checker's organization, while explicit assignments restrict access.
- Password reset uses Laravel's password broker, configured expiry and a database transaction. Reset revokes existing bearer tokens. Forgot-password responses do not reveal whether an account exists.
- Self-service password changes require the current password and revoke other bearer tokens. The current password and password hash are excluded from update audit details. Self-service email changes are prohibited; administrator changes to another user's email clear verification and revoke that user's tokens.
- Bearer tokens expire after seven days by default. Personal staff and attendee invitations expire after 30 days. Reusable public event invitation tokens retain their existing behavior.
- Unknown SSO subjects may link to an existing account by email only when Google is authoritative for that address: a verified Gmail address or verified hosted-domain claim. Other providers require the existing sign-in or password-reset route. Previously linked identities continue working. An identity's changed provider email cannot verify a different local email address.
- QR verification is limited by registration, with separate send/completion limits. Email-code attempts survive resending, and claim updates cannot extend the original 15-minute lifetime.
- Spreadsheet text cells use explicit string values so attendee-controlled values cannot become formulas.
- User and attendee photo uploads use private storage. API resources issue signed, 30-minute image URLs. The signed route validates the stored path and supports legacy files; nginx blocks old direct `/storage/users/` and `/storage/attendees/` URLs. Speaker and event images remain public.
- Image uploads are checked for encoded size and dimensions before image decoding, including base64 uploads and ID-card backgrounds.
- Grid exports have active-job quotas and locking. Cleanup expires completed/failed outputs and stale/orphaned grid files. Synchronous bulk output is bounded; larger exports use the asynchronous grid route.
- Docker database and phpMyAdmin ports bind to loopback. Compose requires configured credentials, and Laravel trusts only configured proxy addresses.
- The frontend partitions queued attendance by account and organization, limits retention, guards against replay under a different login, and offers explicit logout decisions for pending scans. It also sends current-password proof, consumes invitation responses through the auth store, enforces HTTPS for deployed API connections, adds security headers and updates vulnerable transitive packages.

## Deployment requirements

These changes are local source changes. No deployment, production database migration, credential rotation or deletion of existing photos was performed.

1. Deploy frontend and backend together. The password form and backend validation contract change together.
2. Set production `APP_DEBUG=false`, HTTPS `APP_URL` and frontend/API URLs, and actual strong database credentials. Requiring environment variables does **not** rotate passwords in an existing MySQL volume. Rotate any previously used default credentials through a separate controlled database operation.
3. Configure `TRUSTED_PROXIES` to the actual proxy IP addresses or trusted CIDRs. Do not use `*`. Docker bridge proxy addresses may differ from loopback. Verify generated signed URLs use the public HTTPS origin.
4. Deploy the nginx storage-denial rules at every origin serving legacy photos. Remove equivalent public access from any alternative static server or CDN and invalidate cached legacy photo URLs. New private uploads do not rely on these rules; legacy files do. Existing files remain intact for signed-route fallback.
5. Run the Laravel scheduler continuously, using a scheduler service or a host cron that invokes `php artisan schedule:run` every minute. Grid retention is seven days; pending/processing jobs older than 24 hours become failed. The configured queue worker timeout is much shorter than that stale threshold.
6. Use a shared cache supporting atomic locks if running multiple application replicas. Claim counters and export locks must not use separate per-replica caches.
7. Review `AUTH_INVITE_EXPIRE_DAYS` (default 30) and `SANCTUM_TOKEN_EXPIRATION` (minutes; default 10080). Existing older tokens may require users to sign in again. Invitations with no valid `invited_at` must be reissued.
8. Apply the Vercel headers, or equivalent headers on another frontend host. Confirm Google, Microsoft and Facebook sign-in, QR camera access, fonts and realtime connections in the deployed browser. The CSP currently permits HTTPS connections for configurable API/SSO endpoints; narrow this to deployment-specific origins where possible.

## Verification results

- Focused backend security/export/upload suite: 171 passed, 2 skipped, 620 assertions. The two existing oversized-photo tests skip because of test-environment memory constraints; dedicated encoded-size and dimension-limit tests pass.
- Existing QR claim regression suite: 6 passed, 32 assertions. Exact stored URL and null-mission behavior remain covered.
- Frontend native Node tests: 31 passed. Changed frontend files pass ESLint; production Vite build passes with the existing large-chunk warning.
- Laravel Pint and both repositories' whitespace checks pass. Docker Compose configuration validates with placeholder credentials, without starting containers.
- npm locked dependency audit: zero advisories. Composer locked dependency audit: zero advisories and zero abandoned packages.
- Full backend suite: 484 tests; 433 passed, 41 assertion failures, 7 errors, 3 skipped. The clean pre-change baseline had 443 tests; 388 passed, 45 assertion failures, 7 errors, 3 skipped. No current failing test names are new relative to that baseline. These results do not make the full suite green.
- Repository-wide frontend lint remains blocked by 59 errors in existing skill scripts and unrelated application files. All changed frontend source files pass the focused lint check.

## Runtime validation still required

Tests use an isolated SQLite database, fake storage and mocked SSO providers. They do not establish real MySQL lock behavior, real OAuth provider behavior, production proxy configuration, browser camera behavior or the nginx/CDN rollout. The full pre-change test suite also contains existing failures, including outdated fixtures and unavailable ImageMagick `convert`; compare focused security results separately from those failures.
