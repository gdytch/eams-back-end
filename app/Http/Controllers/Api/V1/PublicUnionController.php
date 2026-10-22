<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicUnionController extends Controller
{
    /**
     * Display a listing of unions with only name and code.
     */
    public function index(Request $request)
    {
        $organizationId = Event::organizationIdForInvite($request->query('event'));

        $unions = Union::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->get(['id', 'name', 'code', 'organization_id']);

        return JsonResource::collection($unions);
    }
}
