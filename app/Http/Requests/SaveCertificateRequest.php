<?php

namespace App\Http\Requests;

use App\Enums\CertificateFont;
use App\Enums\CertificateOrientation;
use App\Enums\CertificatePageSize;
use App\Enums\CertificateReleaseRule;
use App\Models\Certificate;
use App\Models\Event;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCertificateRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $gate = Gate::forUser($this->user());

        return $this->isMethod('POST')
            ? $gate->inspect('create', [Certificate::class, $this->route('event')])
            : $gate->inspect('update', $this->route('certificate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:255'],
            // Fractions of the page: x and y of the name's centre, size as a share of page width.
            'name_x' => ['sometimes', 'numeric', 'between:0,1'],
            'name_y' => ['sometimes', 'numeric', 'between:0,1'],
            'name_font_size' => ['sometimes', 'numeric', 'between:0.005,0.25'],
            'font' => ['sometimes', Rule::enum(CertificateFont::class), function (string $attribute, mixed $value, Closure $fail) {
                if ($value === CertificateFont::Google->value && ! $this->route('certificate')?->google_font_path) {
                    $fail('Add a Google Fonts link before choosing a Google font.');
                }
            }],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'page_size' => ['sometimes', Rule::enum(CertificatePageSize::class)],
            'orientation' => ['sometimes', Rule::enum(CertificateOrientation::class)],
            'release_rule' => ['sometimes', Rule::enum(CertificateReleaseRule::class)],
            'include_breakouts' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'release_at' => ['sometimes', 'nullable', 'date'],
            'audience' => ['sometimes', 'array'],
            'audience.union_ids' => ['sometimes', 'array'],
            'audience.union_ids.*' => ['integer', 'distinct', Rule::exists('unions', 'id')->where('organization_id', $this->organizationId())],
            'audience.mission_ids' => ['sometimes', 'array'],
            'audience.mission_ids.*' => ['integer', 'distinct', Rule::exists('missions', 'id')->where('organization_id', $this->organizationId())],
            'audience.attendee_ids' => ['sometimes', 'array'],
            'audience.attendee_ids.*' => ['integer', 'distinct', Rule::exists('attendees', 'id')->where('event_id', $this->eventId())],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $certificate = $this->route('certificate');
            if (! $certificate instanceof Certificate || ! $certificate->isPublished()) {
                return;
            }

            foreach (Certificate::DESIGN_FIELDS as $field) {
                if ($this->has($field) && ! $this->sameAsStored($certificate, $field)) {
                    $validator->errors()->add($field, 'The design is locked while the certificate is published. Unpublish it first.');
                }
            }
        }];
    }

    private function sameAsStored(Certificate $certificate, string $field): bool
    {
        $stored = $certificate->{$field};
        $stored = $stored instanceof \BackedEnum ? $stored->value : $stored;

        return is_numeric($stored)
            ? is_numeric($this->input($field)) && abs((float) $stored - (float) $this->input($field)) < 1e-9
            : strcasecmp((string) $stored, (string) $this->input($field)) === 0;
    }

    /**
     * The audience arrives nested; it is stored in flat columns.
     *
     * @return array<string, mixed>
     */
    public function certificateAttributes(): array
    {
        $data = $this->validated();
        $audience = Arr::pull($data, 'audience', []);

        // Store in the app timezone whatever offset the client sent.
        if (filled($data['release_at'] ?? null)) {
            $data['release_at'] = Carbon::parse($data['release_at'])->setTimezone(config('app.timezone'));
        }

        foreach (['union_ids', 'mission_ids', 'attendee_ids'] as $key) {
            if (array_key_exists($key, $audience)) {
                $data["audience_{$key}"] = array_values($audience[$key]);
            }
        }

        return $data;
    }

    private function eventId(): int
    {
        $event = $this->route('event');

        return $event instanceof Event ? $event->id : (int) $event;
    }

    private function organizationId(): int
    {
        return Event::withoutGlobalScopes()->whereKey($this->eventId())->value('organization_id');
    }
}
