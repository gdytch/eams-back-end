<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChurchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'union_id' => $this->union_id,
            'mission_id' => $this->mission_id,
            'name' => $this->name,
            'address' => $this->address,
            $this->mergeWhen($this->exposesVerification($request), [
                'verified_at' => $this->verified_at,
                'verified_by' => $this->verified_by,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Only admins see the unverified flag; attendees and checkers never do.
     */
    private function exposesVerification(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->isSuperAdmin() || $user->isOrgAdmin());
    }
}
