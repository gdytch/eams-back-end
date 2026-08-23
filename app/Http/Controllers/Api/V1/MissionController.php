<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMissionRequest;
use App\Http\Requests\UpdateMissionRequest;
use App\Http\Resources\MissionResource;
use App\Models\Mission;

class MissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Mission::class);

        return MissionResource::collection(Mission::query()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMissionRequest $request)
    {
        $mission = Mission::create($request->validated());

        return MissionResource::make($mission)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Mission $mission)
    {
        $this->authorize('view', $mission);

        return MissionResource::make($mission);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMissionRequest $request, Mission $mission)
    {
        $mission->update($request->validated());

        return MissionResource::make($mission);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Mission $mission)
    {
        $this->authorize('delete', $mission);

        $mission->delete();

        return response()->noContent();
    }
}

