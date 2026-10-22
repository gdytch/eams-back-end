<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicChurchController extends Controller
{
    /**
     * Churches of one mission for the registration dropdown, verified or not. Never exposes the verification flag.
     */
    public function index(Request $request)
    {
        $data = $request->validate(['mission_id' => ['required', 'integer'], 'event' => ['required', 'string']]);
        $organizationId = Event::organizationIdForInvite($data['event']);
        $missionId = $data['mission_id'];

        $churches = Church::query()
            ->where('mission_id', $missionId)
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'mission_id']);

        return JsonResource::collection($churches);
    }
}
