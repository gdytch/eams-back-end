<?php

namespace App\Services;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Event;
use Illuminate\Support\LazyCollection;

/**
 * Owns the rule for a fully registered Attendee. The result is cached on the row:
 * registration_completed_at (current state) and first_registered_at (set once, never cleared).
 *
 * Release 3 (breakout pick rule) and Release 4 (forms) add their steps in steps().
 */
class RegistrationCompleteness
{
    /**
     * Per-step state, in wizard order: step => missing items (empty when the step is done).
     *
     * @return array<string, array<int, string>>
     */
    public function steps(Attendee $attendee): array
    {
        return [
            'basic_info' => $this->missingBasicInfo($attendee),
            'territory' => $this->missingTerritory($attendee),
            'breakouts' => $this->missingPicks($attendee),
            'forms' => $this->missingForms($attendee),
            'review' => $attendee->registration_confirmed_at === null ? ['confirmation'] : [],
        ];
    }

    /**
     * Human wording for every missing item, in wizard order.
     *
     * @return array<int, string>
     */
    public function missingLabels(Attendee $attendee): array
    {
        $labels = [
            'first_name' => 'Add your first name',
            'last_name' => 'Add your last name',
            'mobile_no' => 'Add your mobile number',
            'organization_level' => 'Choose your organization level',
            'union_id' => 'Add your union',
            'mission_id' => 'Add your mission',
            'church_id' => 'Add your church',
            'breakout_picks' => 'Pick your breakouts',
            'confirmation' => 'Finish your registration',
        ];

        return collect($this->steps($attendee))->flatten()->map(fn (string $item) => $labels[$item] ?? $item)->values()->all();
    }

    public function isComplete(Attendee $attendee): bool
    {
        return array_filter($this->steps($attendee)) === [];
    }

    /**
     * Recompute the cached columns on the model, without saving. Called from the saving hook.
     */
    public function apply(Attendee $attendee): void
    {
        $steps = $this->steps($attendee);

        // The Finish confirmation does not survive an incomplete step.
        if (array_filter(array_diff_key($steps, ['review' => 1])) !== []) {
            $attendee->registration_confirmed_at = null;
            $steps['review'] = ['confirmation'];
        }

        if (array_filter($steps) !== []) {
            $attendee->registration_completed_at = null;

            return;
        }

        $attendee->registration_completed_at ??= now();
        $attendee->first_registered_at ??= $attendee->registration_completed_at;
        $attendee->registered_at ??= $attendee->first_registered_at;
        $attendee->qr_token ??= Attendee::generateUniqueQrToken($attendee->event_id);
    }

    /**
     * Dry run: how many currently complete Attendees of the event the rule would make incomplete.
     */
    public function countBecomingIncomplete(Event $event): int
    {
        return $this->becomingIncomplete($event)->count();
    }

    /**
     * Recompute and save every Attendee of the event whose state changes. Returns how many became incomplete.
     */
    public function recomputeEvent(Event $event): int
    {
        $became = 0;

        $event->attendees()->cursor()->each(function (Attendee $attendee) use (&$became) {
            $wasComplete = $attendee->registration_completed_at !== null;
            $attendee->save();
            $became += $wasComplete && $attendee->getAttribute('registration_completed_at') === null ? 1 : 0;
        });

        return $became;
    }

    private function becomingIncomplete(Event $event): LazyCollection
    {
        return $event->attendees()->whereNotNull('registration_completed_at')->cursor()
            ->filter(fn (Attendee $attendee) => ! $this->isComplete($attendee));
    }

    /** @return array<int, string> */
    private function missingBasicInfo(Attendee $attendee): array
    {
        return collect(['first_name', 'last_name', 'mobile_no'])
            ->filter(fn (string $field) => blank($attendee->{$field}))
            ->values()->all();
    }

    /** @return array<int, string> */
    private function missingPicks(Attendee $attendee): array
    {
        $range = $attendee->event?->pickRange();

        return $range === null || ! BreakoutPickRules::picksBreak($attendee->breakouts, ...$range) ? [] : ['breakout_picks'];
    }

    /**
     * A published required form needs its required fields answered; an optional form only once started.
     * Archived fields never count. The item text is the banner wording itself.
     *
     * @return array<int, string>
     */
    private function missingForms(Attendee $attendee): array
    {
        $forms = $attendee->event?->forms()->published()
            ->with(['fields' => fn ($query) => $query->active()->where('is_required', true)])->get();
        if ($forms === null || $forms->isEmpty()) {
            return [];
        }

        $submissions = $attendee->formSubmissions()->with('answers.options')->get()->keyBy('form_id');

        return $forms->filter(function ($form) use ($submissions) {
            $submission = $submissions->get($form->id);
            if ($submission === null && ! $form->is_required) {
                return false;
            }
            $answered = $submission?->answers->filter->isAnswered()->pluck('form_field_id') ?? collect();

            return $form->fields->contains(fn ($field) => ! $answered->contains($field->id));
        })->map(fn ($form) => "Answer the {$form->title} form")->values()->all();
    }

    /** @return array<int, string> */
    private function missingTerritory(Attendee $attendee): array
    {
        $required = match ($attendee->organization_level) {
            OrganizationLevel::Union => ['union_id'],
            OrganizationLevel::Mission => ['union_id', 'mission_id', 'church_id'],
            default => ['organization_level'],
        };

        return collect($required)->filter(fn (string $field) => blank($attendee->{$field}))->values()->all();
    }
}
