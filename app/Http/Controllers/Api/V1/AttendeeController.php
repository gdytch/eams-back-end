<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckAttendeeDuplicatesRequest;
use App\Http\Requests\StoreAttendeeRequest;
use App\Http\Requests\UpdateAttendeeRequest;
use App\Http\Resources\AttendeeResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AttendeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $query = Attendee::query();

        if ($search = trim((string) $request->string('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        return AttendeeResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAttendeeRequest $request)
    {
        $data = $request->validated();
        $override = (bool) ($data['override_duplicate'] ?? false);
        unset($data['override_duplicate']);

        if (! $override) {
            $duplicates = Attendee::matchingName($data['first_name'], $data['middle_name'] ?? null, $data['last_name'])->get();

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'message' => 'Potential duplicate attendees found. Pass override_duplicate=true to create anyway.',
                    'duplicates' => AttendeeResource::collection($duplicates),
                ], 409);
            }
        }

        $data['created_by'] = $request->user()->id;

        $attendee = Attendee::create($data);

        AuditLog::record('attendee.created', $attendee);

        return AttendeeResource::make($attendee)->response()->setStatusCode(201);
    }

    /**
     * Check for potential duplicate attendees by name, without creating one.
     */
    public function checkDuplicates(CheckAttendeeDuplicatesRequest $request)
    {
        $duplicates = Attendee::matchingName(
            $request->validated('first_name'),
            $request->validated('middle_name'),
            $request->validated('last_name'),
        )->get();

        return AttendeeResource::collection($duplicates);
    }

    /**
     * Display the specified resource.
     */
    public function show(Attendee $attendee)
    {
        $this->authorize('view', $attendee);

        return AttendeeResource::make($attendee);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendeeRequest $request, Attendee $attendee)
    {
        $attendee->update($request->validated());

        AuditLog::record('attendee.updated', $attendee, $request->validated());

        return AttendeeResource::make($attendee);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attendee $attendee)
    {
        $this->authorize('delete', $attendee);

        AuditLog::record('attendee.deleted', $attendee);

        $attendee->delete();

        return response()->noContent();
    }
}
