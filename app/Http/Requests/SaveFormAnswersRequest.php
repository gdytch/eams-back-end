<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Services\FormAnswerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Answers to one published form, for the public wizard (event invite token) and the admin wizard (Attendee).
 * The rules are generated from the form's active fields.
 */
class SaveFormAnswersRequest extends FormRequest
{
    private ?Form $form = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($this->route('eventInviteToken') !== null) {
            return $user->can('register', $this->event());
        }

        return $user->can('registerOnBehalf', $this->attendee());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(FormAnswerService::class)->rules($this->form());
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $known = $this->form()->fields->pluck('id')->map(fn ($id) => (string) $id)->all();

            foreach (array_diff(array_map('strval', array_keys((array) data_get($this->all(), 'answers', []))), $known) as $key) {
                $validator->errors()->add("answers.$key", 'This field does not exist or is archived.');
            }
        }];
    }

    public function form(): Form
    {
        return $this->form ??= app(FormAnswerService::class)->publishedForm($this->event(), (int) $this->route('form'));
    }

    public function event(): Event
    {
        $token = $this->route('eventInviteToken');

        return $token !== null
            ? Event::withoutGlobalScopes()->where('invite_token', $token)->where('status', EventStatus::Published->value)->firstOrFail()
            : Event::withoutGlobalScopes()->findOrFail($this->attendee()->event_id);
    }

    private function attendee(): Attendee
    {
        return $this->route('attendee');
    }
}
