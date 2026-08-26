<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Requests\UploadOrganizationIdCardBackgroundRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Upload (or replace) the organization's attendee-ID-card background image.
     */
    public function uploadIdCardBackground(UploadOrganizationIdCardBackgroundRequest $request, Organization $organization)
    {
        if ($organization->id_card_background_path) {
            Storage::disk('public')->delete($organization->id_card_background_path);
        }

        $path = $request->file('background')->store("organizations/{$organization->id}", 'public');

        $organization->update(['id_card_background_path' => $path]);

        AuditLog::record('organization.id_card_background_updated', $organization);

        return OrganizationResource::make($organization);
    }

    /**
     * Remove the organization's attendee-ID-card background image.
     */
    public function removeIdCardBackground(Request $request, Organization $organization)
    {
        $isOwnOrgAdmin = $organization->id === $request->user()->organization_id && $request->user()->isOrgAdmin();
        abort_unless($request->user()->isSuperAdmin() || $isOwnOrgAdmin, 403);

        if ($organization->id_card_background_path) {
            Storage::disk('public')->delete($organization->id_card_background_path);
        }

        $organization->update(['id_card_background_path' => null]);

        AuditLog::record('organization.id_card_background_removed', $organization);

        return OrganizationResource::make($organization);
    }
}
