<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FormAnswer;
use Illuminate\Support\Facades\Storage;

class FormAnswerFileController extends Controller
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'pdf' => 'application/pdf'];

    public function __invoke(FormAnswer $answer)
    {
        $this->authorize('download', $answer);

        $path = $answer->file_path;
        abort_unless(is_string($path) && $answer->file_deleted_at === null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $answer->file_name ?: basename($path), [
            'Content-Type' => self::TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
