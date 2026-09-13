<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnionRequest;
use App\Http\Requests\UpdateUnionRequest;
use App\Http\Resources\UnionResource;
use App\Models\Union;
use Illuminate\Http\Request;

class UnionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Union::class);

        return UnionResource::collection(Union::query()->paginate($request->input('per_page', 10)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUnionRequest $request)
    {
        $union = Union::create($request->validated());

        return UnionResource::make($union)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Union $union)
    {
        $this->authorize('view', $union);

        return UnionResource::make($union);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUnionRequest $request, Union $union)
    {
        $union->update($request->validated());

        return UnionResource::make($union);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Union $union)
    {
        $this->authorize('delete', $union);

        $union->delete();

        return response()->noContent();
    }
}
