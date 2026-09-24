<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicMissionController extends Controller
{
    /**
     * Display a listing of missions with only name and code.
     */
    public function index(Request $request)
    {
        $missions = Mission::query()
            ->when($request->filled('union_id'), fn ($query) => $query->where('union_id', $request->integer('union_id')))
            ->get(['id', 'name', 'code']);

        return JsonResource::collection($missions);
    }
}
