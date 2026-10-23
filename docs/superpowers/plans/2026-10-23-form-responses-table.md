# Form Responses Table Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins view all attendees' answers to one event form in a paginated, searchable table inside the event detail page.

**Architecture:** Extract the column and row logic of the Excel sheet export into a service shared by the export and a new JSON endpoint. The frontend adds a Responses tab with a form picker and a lazy PrimeVue `DataTable`.

**Tech Stack:** Laravel, PHPUnit, maatwebsite/excel; Vue 3, PrimeVue, Vite, `node:test`.

**Spec:** `AMS-backend/docs/superpowers/specs/2026-10-23-form-responses-table-design.md`

## Global Constraints

- Two independent git repos: backend tasks (1-2) in `AMS-backend/`, frontend tasks (3-4) in `AMS-Frontend/`. Never move files between them. Backend PR merges first.
- Endpoint: `GET /api/v1/events/{event}/forms/{form}/answers`, query `page`, `per_page`, `search`.
- Authorization: `FormPolicy::export` (org admins and super admins only). Form must belong to the event.
- Cell text rules: empty when no answer; `File deleted (event archived)` when `file_deleted_at` set; file name for file answers; option labels joined by `; ` ordered by `sort_order`, `id`; otherwise `value`.
- Columns: active fields in form order, then archived fields that hold answers, labelled `<label> (removed)`.
- Rows: submissions whose attendee exists, sorted by lowercase `last_name first_name`.
- `file_url` is `route('form-answers.file', $answer)` when the file exists and is not deleted, else `null`.
- Read-only table. No editing, no per-column filters, no combined all-forms view.
- Do not add OpenAPI steps (spec was removed from the project).
- Do not commit unless the user asks. The commit steps below run only after the user approves committing.

## Review Focus

- Form with zero fields: returns `columns: []` and rows with only fixed attendee data, no crash.
- Form with zero submissions: empty `data`, `meta.total` 0.
- Search with `%` or `_`: treated as literal text, not wildcards.
- `per_page` huge or 0: clamped to 1..100.
- Form of another event or another organization: 404 or 403, never leaks answers.
- Answer text starting with `=`: shown as plain text in the table (Vue escapes it) and still exported as text.

---

### Task 1: Shared `FormAnswersTable` service; export uses it

**Files:**
- Create: `AMS-backend/app/Services/FormAnswersTable.php`
- Modify: `AMS-backend/app/Exports/FormAnswersSheetExport.php`
- Test: `AMS-backend/tests/Feature/FormAnswersTableTest.php`, existing `tests/Feature/FormAnswersExportTest.php` must stay green

**Interfaces:**
- Produces: `App\Services\FormAnswersTable`
  - `__construct(Form $form)`
  - `columns(): Collection<int, FormField>` (order rules from Global Constraints)
  - `columnLabel(FormField $field): string` (adds ` (removed)` when archived)
  - `submissions(?string $search = null): Builder<FormSubmission>` (attendee exists, eager loads `attendee.union`, `attendee.mission`, `attendee.church`, `answers.options`, sorted by lowercase `last_name first_name`, filtered by search on first name, last name, email)
  - `cell(?FormAnswer $answer): string`
  - `fileUrl(?FormAnswer $answer): ?string`
- Consumes: none.

- [ ] **Step 1: Write failing tests** in `FormAnswersTableTest` (unit-style, `LazilyRefreshDatabase`, factories as in `FormAnswersExportTest::setUp`):
  - `test_columns_put_archived_fields_with_answers_last_and_drop_archived_without_answers`
  - `test_column_label_marks_removed_fields`
  - `test_cell_text_for_missing_text_choice_file_and_deleted_file_answers` (asserts `''`, value, `"S; M"` style labels, file name, `FormAnswersSheetExport::DELETED_FILE_TEXT`)
  - `test_file_url_only_for_existing_files`
  - `test_submissions_skip_missing_attendees_and_sort_by_last_then_first_name`
  - `test_search_matches_name_or_email_and_treats_percent_literally` (an attendee named `100%` matches only `%` search for that text)
- [ ] **Step 2: Run** `cd AMS-backend && php artisan test --filter=FormAnswersTableTest` (or the docker equivalent in `docs/agents`/memory: tests never run against `ams_db`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement** `FormAnswersTable` by moving `columns()`, `cell()` and the submissions query out of `FormAnswersSheetExport`. Escape `%`, `_` and `\` in `search` before `LIKE`.
- [ ] **Step 4: Refactor** `FormAnswersSheetExport` to build `FormAnswersTable` in its constructor when `$form` is non-null and call it for columns, labels, rows, cells and file links. Keep `DELETED_FILE_TEXT` constant and sheet styling untouched.
- [ ] **Step 5: Run** `php artisan test --filter='FormAnswersTableTest|FormAnswersExportTest'`. Expected: PASS.
- [ ] **Step 6: Commit** (only if user approved committing): `refactor: extract form answers table builder`

### Task 2: `GET /events/{event}/forms/{form}/answers` endpoint

**Files:**
- Modify: `AMS-backend/routes/api/v1.php` (next to line 168), `AMS-backend/app/Http/Controllers/Api/V1/FormController.php`
- Create: `AMS-backend/app/Http/Requests/FormAnswersIndexRequest.php`, `AMS-backend/app/Http/Resources/FormAnswersTableResource.php` (or inline array in controller if it stays under ~25 lines; follow `FormResource` style)
- Test: `AMS-backend/tests/Feature/FormAnswersIndexTest.php`

**Interfaces:**
- Consumes: `FormAnswersTable` from Task 1.
- Produces: JSON `{ columns: [{id, label, removed}], data: [{attendee: {id, name, email, territory, registration_status}, cells: {<fieldId>: {text, file_url}}}], meta: {current_page, last_page, per_page, total} }`. Route name `forms.answers`. Throttle `throttle:60,1`. Controller method `FormController::answers(FormAnswersIndexRequest $request, Event $event, Form $form)`.

- [ ] **Step 1: Write failing tests** in `FormAnswersIndexTest` (setUp as in `FormAnswersExportTest`):
  - `test_returns_columns_rows_and_meta` asserts exact JSON structure and values from the spec example
  - `test_cells_cover_text_choice_file_deleted_file_and_missing_answers`
  - `test_removed_field_column_is_flagged`
  - `test_search_filters_rows_and_percent_is_literal`
  - `test_pagination_and_per_page_is_clamped_to_1_100`
  - `test_form_with_no_fields_and_form_with_no_submissions_return_empty_shapes`
  - `test_org_admin_and_super_admin_allowed`
  - `test_checker_attendee_and_other_organization_admin_refused`
  - `test_form_from_another_event_returns_404`
  - `test_answer_text_starting_with_equals_is_returned_verbatim`
- [ ] **Step 2: Run** `php artisan test --filter=FormAnswersIndexTest`. Expected: FAIL, 404 route.
- [ ] **Step 3: Implement** the route (`->name('forms.answers')`, throttle), request validation (`page` int min 1, `per_page` int 1..100 default 25, `search` nullable string max 100), and `answers()`: `authorize('export', [Form::class, $event])`, abort 404 unless `$form->event_id === $event->id`, paginate `FormAnswersTable::submissions($search)`. Attendee `territory` and `registration_status` mirror the export row (`$attendee->territory`, `(string) $attendee->registrationStatus()`). No audit log (read of a table, the export keeps its audit).
- [ ] **Step 4: Run** `php artisan test --filter='FormAnswers'` plus `./vendor/bin/pint --test` if the repo uses pint. Expected: PASS.
- [ ] **Step 5: Commit** (only if approved): `feat: add paginated form answers endpoint`

### Task 3: Frontend API helper and cell helper

**Files:**
- Modify: `AMS-Frontend/src/utils/api/forms.js`
- Create: `AMS-Frontend/src/utils/formResponses.js`, `AMS-Frontend/src/utils/formResponses.test.js`

**Interfaces:**
- Produces:
  - `getFormAnswers(eventId, formId, params)` in `forms.js`: `axios.get('/events/${eventId}/forms/${formId}/answers', { params, skipErrorToast: true })`
  - `cellOf(row, columnId): { text: string, fileUrl: string|null }` in `formResponses.js`; returns `{ text: '', fileUrl: null }` when the cell is absent.
  - `columnHeader(column): string` returns `column.label` plus ` (removed)` when `column.removed` is true and the label does not already end with it.

- [ ] **Step 1: Write failing tests** in `formResponses.test.js` (`node:test`, same style as `forms.test.js`): `cellOf` present cell, absent cell, cell with `file_url`; `columnHeader` for active, removed, and already-suffixed labels.
- [ ] **Step 2: Run** `cd AMS-Frontend && node --test src/utils/formResponses.test.js`. Expected: FAIL, module not found.
- [ ] **Step 3: Implement** both helpers and `getFormAnswers`.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit** (only if approved): `feat: add form responses api and helpers`

### Task 4: Responses tab and table component

**Files:**
- Create: `AMS-Frontend/src/pages/Events/components/forms/ResponsesTab.vue`
- Modify: `AMS-Frontend/src/pages/Events/EventDetail.vue` (tab list ~line 60, panel ~line 387, import ~503, `components` ~518, allowed-tab arrays at ~554, ~646 and the `forms` guards at ~651 and ~761)

**Interfaces:**
- Consumes: `getFormAnswers`, `listForms`, `exportFormAnswers` from `forms.js`; `cellOf`, `columnHeader` from Task 3.
- Produces: `<ResponsesTab :event="event" :can-export="Boolean" />`. Tab value `responses`, shown when `canViewForms`. `?tab=responses` deep link works and is guarded like `forms`.

- [ ] **Step 1: Build `ResponsesTab.vue`** following `FormsTab.vue` structure (header, `Skeleton` loading, error `<p role="alert">`, `EmptyState`):
  - On mount load `listForms(event.id)`, keep published forms only; select the first. No published form: `EmptyState` "No published forms".
  - Form picker (`Select`) and debounced (300 ms) search `InputText`. Changing form or search resets to page 1.
  - `DataTable` with `lazy`, `paginator`, `rows` 25, `totalRecords` from `meta.total`, `@page` reloads. Fixed columns Name, Email, Territory, Status, then `v-for` over `columns` using `columnHeader`. File cells render `<a :href="fileUrl" target="_blank" rel="noopener">` with the text. Empty table message "No responses yet".
  - Ignore stale responses with a request counter, as `EventDetail.vue` does with `eventRequestId`.
  - "Export form answers" button calls `exportFormAnswers` only if `canExport`, reusing the download code path from `FormsTab.vue` (extract nothing new unless that code is already a shared helper).
- [ ] **Step 2: Wire into `EventDetail.vue`:** add `<Tab v-if="canViewForms" value="responses">Responses</Tab>` after Forms, the matching `TabPanel` with `ResponsesTab` (`v-if="event"`, `:key="event.id"`, `:can-export="canExportFormAnswers"`), import and register it, add `'responses'` to both allowed-tab arrays, and add `(tab === 'responses' && !this.canViewForms)` style guards beside the `forms` ones.
- [ ] **Step 3: Verify in the app** (docker stack and factory accounts from memory `ems-local-testing`, never `ams_db`): log in as org admin, open an event with a published form and several submissions, open Responses. Check: table shows all attendees, pagination and search work, file link downloads, removed field column appears, deep link `?tab=responses` opens the tab, checker account does not see the tab, archived event shows deleted-file text. Run `npm run lint` and `node --test src/utils/formResponses.test.js`. Expected: clean and PASS.
- [ ] **Step 4: Commit** (only if approved): `feat: add form responses tab`

---

## Self-review notes

- Spec coverage: shared builder (T1), endpoint, authorization, tests (T2), API client and cell helper (T3), tab, picker, pagination, search, export button, file links, states (T4). Out-of-scope items have no tasks.
- Names match across tasks: `FormAnswersTable`, `getFormAnswers`, `cellOf`, `columnHeader`, tab value `responses`.
- Review Focus items map to tests: zero fields/submissions, literal `%`, `per_page` clamp and cross-event 404 (T2), `=` text (T2, plus Vue escaping in T4).
