<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChurchRequest;
use App\Http\Requests\UpdateChurchRequest;
use App\Http\Resources\ChurchResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Church;
use App\Models\Event;
use App\Services\ChurchDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChurchController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Church::class);

        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->isOrgAdmin();

        return ChurchResource::collection(
            Church::query()
                ->when($request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->integer('mission_id')))
                ->when($isAdmin && $request->boolean('unverified'), fn ($query) => $query->whereNull('verified_at'))
                ->paginate($request->input('per_page', 10))
        );
    }

    public function store(StoreChurchRequest $request, ChurchDuplicateService $duplicates)
    {
        $data = $request->validated();
        $confirmed = (bool) ($data['confirm_same_name'] ?? false);
        unset($data['confirm_same_name']);

        $found = $duplicates->check($data['mission_id'], $data['name'], $data['address']);

        if ($response = $this->duplicateResponse($found, $confirmed)) {
            return $response;
        }

        $user = $request->user();
        if ($user->isSuperAdmin() || $user->isOrgAdmin()) {
            $data['verified_at'] = now();
            $data['verified_by'] = $user->id;
        }

        $church = Church::create($data);

        return ChurchResource::make($church)
            ->additional(['similar_churches' => ChurchResource::collection($found['similar'])->resolve($request)])
            ->response()->setStatusCode(201);
    }

    public function show(Church $church)
    {
        $this->authorize('view', $church);

        return ChurchResource::make($church);
    }

    public function update(UpdateChurchRequest $request, Church $church, ChurchDuplicateService $duplicates)
    {
        $data = $request->validated();
        $confirmed = (bool) ($data['confirm_same_name'] ?? false);
        unset($data['confirm_same_name']);

        $address = $data['address'] ?? $church->address;
        if ($address !== null) {
            $found = $duplicates->check($data['mission_id'] ?? $church->mission_id, $data['name'] ?? $church->name, $address, $church->id);

            if ($response = $this->duplicateResponse($found, $confirmed)) {
                return $response;
            }
        }

        $church->update($data);

        return ChurchResource::make($church);
    }

    public function destroy(Church $church)
    {
        $this->authorize('delete', $church);
        $church->delete();

        return response()->noContent();
    }

    public function verify(Request $request, Church $church)
    {
        $this->authorize('update', $church);

        if (trim((string) $church->address) === '') {
            throw ValidationException::withMessages(['address' => 'Add an address before verifying this church.']);
        }

        $church->update(['verified_at' => now(), 'verified_by' => $request->user()->id]);
        AuditLog::record('church.verified', $church);

        return ChurchResource::make($church);
    }

    /**
     * Merge this church into another one of the same mission, then delete it.
     */
    public function merge(Request $request, Church $church)
    {
        $this->authorize('update', $church);

        $targetId = $request->validate(['target_church_id' => ['required', 'integer']])['target_church_id'];
        $target = Church::query()->where('mission_id', $church->mission_id)->whereKeyNot($church->id)->find($targetId);

        if ($target === null) {
            throw ValidationException::withMessages(['target_church_id' => 'Choose another church of the same mission.']);
        }

        DB::transaction(function () use ($church, $target) {
            $archivedEventIds = Event::withoutGlobalScopes()->whereNotNull('archived_at')->select('id');
            $attendees = Attendee::withoutGlobalScopes()->where('church_id', $church->id);

            $repointed = (clone $attendees)->where(fn ($query) => $query->whereNull('event_id')->orWhereNotIn('event_id', $archivedEventIds))->update(['church_id' => $target->id]);
            $cleared = (clone $attendees)->update(['church_id' => null]);

            AuditLog::record('church.merged', $target, [
                'source_church' => $church->only(['id', 'name', 'address', 'mission_id']),
                'target_church_id' => $target->id,
                'attendees_repointed' => $repointed,
                'archived_attendees_cleared' => $cleared,
            ]);

            $church->delete();
        });

        return ChurchResource::make($target);
    }

    /**
     * @param  array{exact: ?Church, same_name: Collection<int, Church>, similar: Collection<int, Church>}  $found
     */
    private function duplicateResponse(array $found, bool $confirmed): ?JsonResponse
    {
        if ($found['exact'] !== null) {
            return response()->json([
                'message' => 'A church with this name and address already exists in this mission.',
                'existing_church' => ChurchResource::make($found['exact']),
            ], 422);
        }

        if ($found['same_name']->isNotEmpty() && ! $confirmed) {
            return response()->json([
                'message' => "A church with this name exists at {$found['same_name']->first()->address}. Is this yours? Resend with confirm_same_name to create it anyway.",
                'existing_churches' => ChurchResource::collection($found['same_name']),
            ], 422);
        }

        return null;
    }
}
