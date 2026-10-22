<?php

namespace App\Http\Requests\Concerns;

use App\Enums\OrganizationLevel;
use App\Models\Church;
use App\Models\Mission;
use Illuminate\Validation\Validator;

/**
 * Territory rules by organization level: union-level answers the union only, mission-level answers union, mission and church.
 */
trait ValidatesTerritory
{
    private const TERRITORY_KEYS = ['organization_level', 'union_id', 'mission_id', 'church_id'];

    /**
     * Territory as it will be stored: the request over the existing values. Null when the request does not touch territory.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function currentTerritory(): ?array;

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $territory = $this->currentTerritory();

            if ($territory === null || $validator->errors()->hasAny(self::TERRITORY_KEYS)) {
                return;
            }

            if ($territory['organization_level'] !== OrganizationLevel::Mission->value) {
                return;
            }

            foreach (['union_id', 'mission_id', 'church_id'] as $key) {
                if (empty($territory[$key])) {
                    $validator->errors()->add($key, 'This field is required for a mission-level attendee.');
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! Mission::withoutGlobalScopes()->whereKey($territory['mission_id'])->where('union_id', $territory['union_id'])->exists()) {
                $validator->errors()->add('mission_id', 'The mission does not belong to the union.');
            } elseif (! Church::withoutGlobalScopes()->whereKey($territory['church_id'])->where('mission_id', $territory['mission_id'])->exists()) {
                $validator->errors()->add('church_id', 'The church does not belong to the mission.');
            }
        }];
    }

    /**
     * A union-level attendee keeps no mission or church.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $territory = $this->currentTerritory();

        if ($territory !== null && $territory['organization_level'] === OrganizationLevel::Union->value) {
            $data['mission_id'] = null;
            $data['church_id'] = null;
        }

        return data_get($data, $key, $default);
    }
}
