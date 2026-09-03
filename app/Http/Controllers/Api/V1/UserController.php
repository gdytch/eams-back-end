<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\SyncUserEventAccessRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UploadUserPhotoRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query();

        if (! $request->user()->isSuperAdmin()) {
            $query->where('organization_id', $request->user()->organization_id);
        }

        return UserResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['organization_id'] ??= $request->user()->organization_id;
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        AuditLog::record('user.created', $user, ['role' => $data['role']]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return UserResource::make($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        if (array_key_exists('password', $data)) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        AuditLog::record('user.updated', $user, array_diff_key($data, ['password' => null]));

        return UserResource::make($user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        AuditLog::record('user.deleted', $user);

        $user->delete();

        return response()->noContent();
    }

    /**
     * Replace the set of events a Checker is restricted to (empty = full org access).
     */
    public function syncEventAccess(SyncUserEventAccessRequest $request, User $user)
    {
        $user->accessibleEvents()->sync($request->validated('event_ids'));

        return EventResource::collection($user->accessibleEvents()->get());
    }

    /**
     * Upload (or replace) the user's profile photo.
     */
    public function uploadPhoto(UploadUserPhotoRequest $request, User $user)
    {
        $service = new ImageUploadService;
        $input = $request->file('photo') ?? $request->input('photo');

        $paths = $service->process(
            $input,
            preset: 'profile_photo',
            directory: "users/{$user->id}",
            prefix: 'photo',
        );

        $user->update(['photo_paths' => $paths]);

        AuditLog::record('user.photo_updated', $user);

        return UserResource::make($user);
    }

    /**
     * Remove the user's profile photo.
     */
    public function removePhoto(User $user)
    {
        $this->authorize('update', $user);

        if ($user->photo_paths) {
            foreach ($user->photo_paths as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $user->update(['photo_paths' => null]);

        AuditLog::record('user.photo_removed', $user);

        return UserResource::make($user);
    }
}
