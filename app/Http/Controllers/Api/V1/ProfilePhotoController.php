<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public function __invoke(string $kind, int $id, string $size)
    {
        $model = match ($kind) {
            'users' => User::withoutGlobalScopes()->findOrFail($id),
            'attendees' => Attendee::withoutGlobalScopes()->findOrFail($id),
            default => abort(404),
        };
        $path = $model->photo_paths[$size] ?? null;
        abort_unless(is_string($path) && preg_match('#^(users|attendees)/[0-9]+/photo-(sm|md|lg|original)\.webp$#D', $path), 404);

        // Legacy public files remain readable here while nginx blocks their direct URLs.
        $disk = Storage::disk('local')->exists($path) ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return response()->file(Storage::disk($disk)->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
