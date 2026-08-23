<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Organization::class);

        return OrganizationResource::collection(Organization::query()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrganizationRequest $request)
    {
        $organization = Organization::create($request->validated());

        AuditLog::record('organization.created', $organization);

        return OrganizationResource::make($organization)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Organization $organization)
    {
        $this->authorize('view', $organization);

        return OrganizationResource::make($organization);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrganizationRequest $request, Organization $organization)
    {
        $organization->update($request->validated());

        AuditLog::record('organization.updated', $organization, $request->validated());

        return OrganizationResource::make($organization);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Organization $organization)
    {
        $this->authorize('delete', $organization);

        AuditLog::record('organization.deleted', $organization);

        $organization->delete();

        return response()->noContent();
    }
}
