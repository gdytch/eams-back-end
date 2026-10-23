# Form responses table

## Goal

Admins view every attendee's answers to an event form in one table, without opening each attendee profile.

## Decisions

- One table per form, chosen with a form picker.
- Rows are attendees with a submission. Columns are Name, Email, Territory, Registration status, then one column per form field.
- Read-only. No editing in the table.
- Org admins and super admins only (same as the existing export).

## Out of scope

- Editing answers in the table.
- Per-column filters.
- A combined all-forms table.

## Backend (`AMS-backend`)

### Shared row builder

Extract the column and row logic of `App\Exports\FormAnswersSheetExport` into a service (for example `App\Services\FormAnswersTable`). The Excel export and the new endpoint both use it, so the table and the file always match.

Rules the service keeps from the export:

- Columns: active fields in form order, then archived fields that hold answers (label suffix `(removed)`).
- Rows: one per `FormSubmission` whose attendee exists, sorted by lowercase `last_name first_name`.
- Cell text: empty when no answer; `File deleted (event archived)` when `file_deleted_at` is set; file name for file answers; option labels joined by `; ` for choice answers; otherwise `value`.

### Endpoint

`GET /api/v1/events/{event}/forms/{form}/answers`

- Query: `page`, `per_page`, `search` (matches attendee name or email).
- Authorization: `FormPolicy::export` (admin only). The form must belong to the event.
- Response:

```json
{
  "columns": [{ "id": 1, "label": "T-shirt size", "removed": false }],
  "data": [
    {
      "attendee": { "id": 9, "name": "Ada Cruz", "email": "ada@example.com", "territory": "Union / Mission / Church", "registration_status": "complete" },
      "cells": { "1": { "text": "M", "file_url": null } }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

- `file_url` is `route('form-answers.file', $answer)` when the file exists and is not deleted; otherwise `null`.
- Throttle like the export route.

### Backend tests

- Response shape, columns order, removed-field column.
- Cell text for text, choice, file, deleted-file and missing answers.
- Search and pagination.
- Authorization: org admin and super admin allowed; checker, attendee and other-organization admin refused.
- Form from another event returns 404.
- The existing export test still passes after the extraction.

## Frontend (`AMS-Frontend`)

- API client: add `getFormAnswers(eventId, formId, params)` to `src/utils/api/forms.js`.
- New **Responses** tab on `EventDetail.vue`, next to the Forms tab. Only admin roles see it.
- Tab content (new component under `src/pages/Events/components/forms/`):
  - Form picker over published forms. Empty state when the event has none.
  - PrimeVue `DataTable` with lazy server pagination and a search box (debounced).
  - Fixed columns, then dynamic field columns from `columns`. File cells render a link to `file_url`.
  - Button that calls existing `exportFormAnswers`.
  - Loading, empty and error states follow the patterns in `FormsTab.vue`.
- Frontend tests cover the API helper and the cell rendering helper, following the `*.test.js` pattern in `src/utils`.

## Delivery

Two independent repos, two PRs: backend first (endpoint), then frontend (tab). Issues go to `gdytch/eams-back-end` and `gdytch/eams-front-end`.
