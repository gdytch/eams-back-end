<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FormStatus;
use App\Http\Controllers\Controller;
use App\Exports\FormAnswersExport;
use App\Http\Requests\CopyFormRequest;
use App\Http\Requests\ReorderFormsRequest;
use App\Http\Requests\SaveFormRequest;
use App\Http\Resources\FormResource;
use App\Jobs\RecomputeEventCompletenessJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Form;
use App\Services\FormAuthoring;
use App\Services\RegistrationCompleteness;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class FormController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('viewAny', [Form::class, $event]);

        return FormResource::collection($event->forms()->withCount('submissions')->with('fields.options')->get());
    }

    public function export(Event $event)
    {
        $this->authorize('export', [Form::class, $event]);

        AuditLog::record('form_answers.exported', $event);

        return Excel::download(new FormAnswersExport($event), "event-{$event->id}-form-answers.xlsx");
    }

    public function store(SaveFormRequest $request, Event $event, FormAuthoring $authoring)
    {
        $form = DB::transaction(function () use ($request, $event, $authoring) {
            $form = $event->forms()->create([
                'organization_id' => $event->organization_id,
                'title' => $request->validated('title'),
                'status' => FormStatus::Draft,
                'sort_order' => $authoring->nextSortOrder($event),
            ]);
            $authoring->sync($form, $request->validated());
            AuditLog::record('form.created', $form);

            return $form;
        });

        return FormResource::make($this->loaded($form))->response()->setStatusCode(201);
    }

    public function show(Event $event, Form $form)
    {
        $this->authorize('view', $form);

        return FormResource::make($this->loaded($form));
    }

    public function update(SaveFormRequest $request, Event $event, Form $form, FormAuthoring $authoring, RegistrationCompleteness $completeness)
    {
        $tightens = DB::transaction(function () use ($request, $event, $form, $authoring, $completeness) {
            Form::withoutGlobalScopes()->whereKey($form->id)->lockForUpdate()->first();
            $form->refresh();

            $tightens = $authoring->sync($form, $request->validated()) && $form->isPublished();
            if ($tightens) {
                $this->confirmOrAbort($request, $completeness, $event, 'Saving this form makes registered Attendees incomplete. Send confirm to continue.');
            }
            AuditLog::record('form.updated', $form);

            return $tightens;
        });

        if ($tightens) {
            RecomputeEventCompletenessJob::dispatch($event);
        }

        return FormResource::make($this->loaded($form));
    }

    public function destroy(Event $event, Form $form)
    {
        $this->authorize('delete', $form);

        $deleted = DB::transaction(function () use ($form) {
            Form::withoutGlobalScopes()->whereKey($form->id)->lockForUpdate()->first();
            if ($form->answers()->exists()) {
                return false;
            }
            $form->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json(['message' => 'A form with answers cannot be deleted. Unpublish it instead.'], 422);
        }

        AuditLog::record('form.deleted', $form);

        return response()->noContent();
    }

    public function publish(Request $request, Event $event, Form $form, RegistrationCompleteness $completeness)
    {
        $this->authorize('update', $form);
        $request->validate(['confirm' => ['sometimes', 'boolean']]);

        $changed = DB::transaction(function () use ($request, $event, $form, $completeness) {
            Form::withoutGlobalScopes()->whereKey($form->id)->lockForUpdate()->first();
            $form->refresh();
            if ($form->isPublished()) {
                return false;
            }
            $form->update(['status' => FormStatus::Published]);
            if ($form->is_required) {
                $this->confirmOrAbort($request, $completeness, $event, 'Publishing this form makes registered Attendees incomplete. Send confirm to continue.');
            }
            AuditLog::record('form.published', $form);

            return true;
        });

        if ($changed && $form->is_required) {
            RecomputeEventCompletenessJob::dispatch($event);
        }

        return FormResource::make($this->loaded($form));
    }

    public function unpublish(Event $event, Form $form)
    {
        $this->authorize('update', $form);

        DB::transaction(function () use ($form) {
            Form::withoutGlobalScopes()->whereKey($form->id)->lockForUpdate()->first();
            $form->refresh();
            if ($form->isPublished()) {
                $form->update(['status' => FormStatus::Draft]);
                AuditLog::record('form.unpublished', $form);
            }
        });

        return FormResource::make($this->loaded($form));
    }

    public function order(ReorderFormsRequest $request, Event $event)
    {
        $ids = array_map('intval', $request->validated('form_ids'));

        DB::transaction(function () use ($event, $ids) {
            Event::withoutGlobalScopes()->whereKey($event->id)->lockForUpdate()->first();
            if ($event->forms()->pluck('id')->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
                throw ValidationException::withMessages(['form_ids' => 'List every form of the event exactly once.']);
            }
            foreach ($ids as $position => $id) {
                $event->forms()->whereKey($id)->update(['sort_order' => $position]);
            }
            AuditLog::record('form.reordered', $event, ['form_ids' => $ids]);
        });

        return FormResource::collection($event->forms()->withCount('submissions')->with('fields.options')->get());
    }

    public function copy(CopyFormRequest $request, Event $event, FormAuthoring $authoring)
    {
        // Looked up across organizations so another organization's form is refused, not hidden.
        $source = Form::withoutGlobalScopes()->find($request->validated('source_form_id'));
        if ($source === null || $source->organization_id !== $event->organization_id) {
            throw ValidationException::withMessages(['source_form_id' => 'Choose a form from an event of the same organization.']);
        }

        $copy = DB::transaction(function () use ($authoring, $source, $event) {
            $copy = $authoring->copy($source, $event);
            AuditLog::record('form.copied', $copy, ['source_form_id' => $source->id]);

            return $copy;
        });

        return FormResource::make($this->loaded($copy))->response()->setStatusCode(201);
    }

    /** Dry run inside the caller's transaction: throwing rolls the change back. */
    private function confirmOrAbort(Request $request, RegistrationCompleteness $completeness, Event $event, string $message): void
    {
        $count = $completeness->countBecomingIncomplete($event);
        if ($count > 0 && ! $request->boolean('confirm')) {
            throw new HttpResponseException(response()->json([
                'message' => $message,
                'requires_confirmation' => true,
                'would_become_incomplete' => $count,
            ], 409));
        }
    }

    private function loaded(Form $form): Form
    {
        return $form->refresh()->load('fields.options')->loadCount('submissions');
    }
}
