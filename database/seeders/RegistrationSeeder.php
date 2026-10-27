<?php

namespace Database\Seeders;

use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Finishes the registration of seeded Attendees: picks breakouts and answers published forms.
 * Run after BreakoutSeeder and FormSeeder. Roughly one in ten Attendees stays a draft.
 */
class RegistrationSeeder extends Seeder
{
    public function run(): void
    {
        // Saving a completed Attendee fires the welcome mail observer; seeding must never send mail.
        Mail::fake();

        /** @var array<string, User> $users */
        $users = [];

        foreach (Event::withoutGlobalScopes()->get() as $event) {
            $slots = $event->breakouts()->withoutGlobalScopes()->get()->groupBy(fn ($b) => $b->date->toDateString().' '.substr($b->start_time, 0, 5));
            $forms = Form::withoutGlobalScopes()->where('event_id', $event->id)->published()
                ->with(['fields' => fn ($q) => $q->active()->with(['options' => fn ($o) => $o->active()])])->get();

            $filled = [];
            Attendee::query()->withoutGlobalScopes()->where('event_id', $event->id)->get()
                ->each(function (Attendee $attendee) use ($slots, $forms, &$filled, &$users) {
                    $draft = fake()->boolean(10);

                    $this->linkAccount($attendee, $users);
                    $this->pickBreakouts($attendee, $slots, $filled, $draft);
                    $this->answerForms($attendee, $forms, $draft);

                    // The saving hook recomputes completeness from the picks and answers just stored.
                    $attendee->registration_confirmed_at = $draft ? null : now();
                    $attendee->save();
                });
        }
    }

    /**
     * One breakout per slot, skipping full ones. A draft keeps at most one pick.
     *
     * @param  Collection<string, Collection<int, Breakout>>  $slots
     * @param  array<int, int>  $filled
     */
    private function pickBreakouts(Attendee $attendee, Collection $slots, array &$filled, bool $draft): void
    {
        $picks = [];

        foreach ($slots->take($draft ? 1 : PHP_INT_MAX) as $breakouts) {
            $open = $breakouts->filter(fn ($b) => $b->capacity === null || ($filled[$b->id] ?? 0) < $b->capacity);
            if ($open->isEmpty()) {
                continue;
            }
            $breakout = $open->random();
            $filled[$breakout->id] = ($filled[$breakout->id] ?? 0) + 1;
            $picks[$breakout->id] = ['overbooked' => false];
        }

        $attendee->breakouts()->sync($picks);
        $attendee->unsetRelation('breakouts');
        $attendee->forceFill(['picks_saved_at' => now()]);
    }

    /** A draft skips the required forms; everyone else answers every required field. */
    private function answerForms(Attendee $attendee, Collection $forms, bool $draft): void
    {
        foreach ($forms as $form) {
            if ($draft && $form->is_required) {
                continue;
            }
            if (! $form->is_required && fake()->boolean(40)) {
                continue;
            }

            $submission = $attendee->formSubmissions()->firstOrCreate(['form_id' => $form->id]);

            foreach ($form->fields as $field) {
                if (! $field->is_required && fake()->boolean(30)) {
                    continue;
                }

                $answer = $submission->answers()->updateOrCreate(
                    ['form_field_id' => $field->id],
                    ['value' => $this->textValue($attendee, $field)],
                );

                if ($field->type->hasOptions()) {
                    $count = $field->type === FormFieldType::MultipleChoice ? fake()->numberBetween(1, 2) : 1;
                    $answer->options()->sync($field->options->random(min($count, $field->options->count()))->pluck('id'));
                }
            }
        }
        $attendee->unsetRelation('formSubmissions');
    }

    private function textValue(Attendee $attendee, FormField $field): ?string
    {
        return match ($field->type) {
            FormFieldType::ShortText => match ($field->label) {
                'Contact name' => fake()->name(),
                'Contact mobile number' => fake()->phoneNumber(),
                'Food allergies' => fake()->randomElement(['None', 'Peanuts', 'Shellfish', 'Dairy', 'Eggs']),
                default => fake()->words(2, true),
            },
            FormFieldType::LongText => fake()->sentence(),
            FormFieldType::Number => (string) fake()->numberBetween(0, 4),
            default => null,
        };
    }

    /**
     * Gives the Attendee a verified login (password "password") shared across the events of one email.
     * Linking clears the invite token the way a claimed invite does.
     *
     * @param  array<string, User>  $users
     */
    private function linkAccount(Attendee $attendee, array &$users): void
    {
        if (blank($attendee->email_address) || $attendee->user_id !== null) {
            return;
        }

        $email = mb_strtolower($attendee->email_address);
        // The factory hashes "password" once and reuses the hash, so seeding stays fast.
        $users[$email] ??= User::withoutGlobalScopes()->where('email', $email)->first()
            ?? User::factory()->attendee()->create([
                'name' => $attendee->first_name.' '.$attendee->last_name,
                'first_name' => $attendee->first_name,
                'middle_name' => $attendee->middle_name,
                'last_name' => $attendee->last_name,
                'email' => $email,
            ]);

        $attendee->user_id = $users[$email]->id;
        $attendee->invite_token = null;
    }
}
