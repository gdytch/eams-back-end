<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChurchRequest;
use App\Http\Requests\UpdateChurchRequest;
use App\Http\Resources\ChurchResource;
use App\Models\Church;

class ChurchController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Church::class);

        return ChurchResource::collection(Church::query()->paginate());
    }

    public function store(StoreChurchRequest $request)
    {
        $church = Church::create($request->validated());

        return ChurchResource::make($church)->response()->setStatusCode(201);
    }

    public function show(Church $church)
    {
        $this->authorize('view', $church);

        return ChurchResource::make($church);
    }

    public function update(UpdateChurchRequest $request, Church $church)
    {
        $church->update($request->validated());

        return ChurchResource::make($church);
    }

    public function destroy(Church $church)
    {
        $this->authorize('delete', $church);
        $church->delete();

        return response()->noContent();
    }
}
